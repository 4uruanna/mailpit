<?php

declare(strict_types=1);

namespace App\Action;

use App\Http\Json;
use App\Mailpit\InvalidPayloadException;
use App\Mailpit\Message;
use App\Middleware\IpFilterMiddleware;
use App\Persistence\MessageRepository;
use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /hook/mailpit : reçoit un mail (résumé du webhook Mailpit, ou mail complet envoyé par le relais).
 *
 * Réponses :
 *   201 {"status":"created", "id":"…", "complete":false, "missing":["2","raw"], "too_large":["3"]}
 *   200 {"status":"updated"|"duplicate", …}   déjà connu (même ID Mailpit)
 *   400 / 413 / 415 / 422                     requête invalide
 *
 * « missing » liste les pièces annoncées dans « Parts » dont le contenu est attendu
 * (PUT /hook/mailpit/{ID}/parts/{PartID}). « too_large » liste celles qui dépassent
 * la limite de taille : elles sont décrites en base, mais leur contenu n'est pas enregistré.
 */
final readonly class MailpitWebhookAction
{
    public function __construct(
        private MessageRepository $repository,
        private ResponseFactoryInterface $responseFactory,
        private int $maxBodySize,
        private int $maxPartSize,
        private int $maxRawSize,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
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
            $message = Message::fromArray($data);
        } catch (InvalidPayloadException $e) {
            return $this->error(422, $e->getMessage());
        }

        // Le corps et les en-têtes sont rangés dans leurs propres colonnes :
        // on ne les duplique pas dans la colonne « payload ».
        $payload = $raw;
        if (array_intersect_key($data, ['Text' => 0, 'HTML' => 0, 'Headers' => 0]) !== []) {
            unset($data['Text'], $data['HTML'], $data['Headers']);
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $label = $request->getHeaderLine('Mailpit-Label');
        $result = $this->repository->save(
            $message,
            $label === '' ? null : $label,
            (string) $request->getAttribute(IpFilterMiddleware::ATTRIBUTE, ''),
            $payload,
            $this->maxPartSize,
            $this->maxRawSize,
        );

        return Json::response(
            $this->responseFactory,
            $result['status'] === 'created' ? 201 : 200,
            ['id' => $message->id] + $result,
        );
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return Json::response($this->responseFactory, $status, ['error' => $message]);
    }
}
