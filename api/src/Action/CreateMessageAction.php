<?php

declare(strict_types=1);

namespace App\Action;

use App\Http\Json;
use App\Mailpit\InvalidPayloadException;
use App\Mailpit\Message;
use App\Middleware\IpFilterMiddleware;
use App\Persistence\FileStore;
use App\Persistence\MessageRepository;
use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/messages : reçoit un mail complet, envoyé par le relais (seul autorisé, voir TokenAuthMiddleware).
 *
 * Réponses :
 *   201 {"status":"created", "id":"…", "complete":false, "missing":["2","1.3"], "too_large":["4"]}
 *   200 {"status":"updated"|"duplicate", …}   déjà connu (même ID Mailpit)
 *   400 / 413 / 415 / 422                     requête invalide
 *
 * « missing » liste les pièces annoncées dans « Parts » dont le contenu est attendu
 * (POST /v1/messages/{id}/part/{partId}). « too_large » liste celles qui dépassent
 * la limite de taille : elles sont décrites en base, mais leur contenu n'est pas enregistré.
 *
 * Après chaque nouveau mail, les plus anciens au-delà de webhook.max_messages sont supprimés.
 * Le reste du nettoyage (mails trop anciens, fichiers orphelins) est fait par Maintenance.
 */
final readonly class CreateMessageAction
{
    public function __construct(
        private MessageRepository $repository,
        private FileStore $files,
        private ResponseFactoryInterface $responseFactory,
        private int $maxBodySize,
        private int $maxPartSize,
        private int $maxMessages,
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
        $json = '';
        while (!$body->eof() && strlen($json) <= $this->maxBodySize) {
            $chunk = $body->read($this->maxBodySize + 1 - strlen($json));
            if ($chunk === '') {
                break;
            }
            $json .= $chunk;
        }
        if (strlen($json) > $this->maxBodySize) {
            return $this->error(413, 'corps de requête trop volumineux');
        }

        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
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
        $payload = $json;
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
        );

        if ($result['status'] === 'created') {
            $this->prune();
        }

        return Json::response(
            $this->responseFactory,
            $result['status'] === 'created' ? 201 : 200,
            ['id' => $message->id] + $result,
        );
    }

    /**
     * Ne garde que les max_messages mails les plus récents. Un échec ici ne doit pas faire
     * échouer l'enregistrement du mail (déjà validé) : il est seulement écrit dans le log,
     * et la purge sera refaite au prochain mail (ou par Maintenance, qui fait aussi la suite d'une
     * grosse purge).
     */
    private function prune(): void
    {
        if ($this->maxMessages === 0) {
            return;
        }

        try {
            $deleted = $this->repository->prune($this->maxMessages);
        } catch (\Throwable $e) {
            error_log('[prune] purge des anciens mails impossible : ' . $e->getMessage());

            return;
        }

        foreach ($deleted as $mailpitId) {
            if (!$this->files->deleteMessage($mailpitId)) {
                error_log(sprintf('[prune] fichiers de %s pas tous effacés', $mailpitId));
            }
        }
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return Json::response($this->responseFactory, $status, ['error' => $message]);
    }
}
