<?php

declare(strict_types=1);

namespace App\Action;

use App\Http\Json;
use App\Mailpit\InvalidPayloadException;
use App\Mailpit\MessageSummary;
use App\Middleware\IpFilterMiddleware;
use App\Persistence\MessageRepository;
use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /hook/mailpit : reçoit le webhook « nouveau message » de Mailpit.
 *
 * Réponses : 201 (enregistré), 200 (déjà connu), 403 (jeton invalide),
 * 400/413/415/422 (requête invalide).
 */
final readonly class MailpitWebhookAction
{
    public function __construct(
        private MessageRepository $repository,
        private ResponseFactoryInterface $responseFactory,
        private int $maxBodySize,
        private ?string $token = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->token !== null) {
            $given = $request->getQueryParams()['token'] ?? '';
            if (!is_string($given) || !hash_equals($this->token, $given)) {
                error_log('[webhook] jeton absent ou invalide');

                return $this->error(403, 'Forbidden');
            }
        }

        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType !== 'application/json') {
            return $this->error(415, 'Content-Type application/json attendu');
        }

        $declaredLength = $request->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && (int) $declaredLength > $this->maxBodySize) {
            return $this->error(413, 'corps de requête trop volumineux');
        }

        // Lecture bornée : on ne charge jamais plus de max_body_size + 1 octets.
        $body = $request->getBody();
        $raw = '';
        while (!$body->eof() && strlen($raw) <= $this->maxBodySize) {
            $chunk = $body->read($this->maxBodySize + 1 - strlen($raw));
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        if (strlen($raw) > $this->maxBodySize) {
            return $this->error(413, 'corps de requête trop volumineux');
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(400, 'JSON invalide');
        }

        try {
            $message = MessageSummary::fromArray($data);
        } catch (InvalidPayloadException $e) {
            return $this->error(422, $e->getMessage());
        }

        $label = $request->getHeaderLine('Mailpit-Label');
        $inserted = $this->repository->save(
            $message,
            $label === '' ? null : $label,
            (string) $request->getAttribute(IpFilterMiddleware::ATTRIBUTE, ''),
            $raw,
        );

        return Json::response(
            $this->responseFactory,
            $inserted ? 201 : 200,
            ['status' => $inserted ? 'created' : 'duplicate', 'id' => $message->id],
        );
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return Json::response($this->responseFactory, $status, ['error' => $message]);
    }
}
