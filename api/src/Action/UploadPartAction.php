<?php

declare(strict_types=1);

namespace App\Action;

use App\Http\Json;
use App\Mailpit\Message;
use App\Mailpit\Part;
use App\Persistence\FileStore;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/messages/{id}/part/{partId} : contenu d'une pièce annoncée dans « Parts »
 * (pièce jointe ou image intégrée).
 *
 * Corps : le contenu binaire, tel quel (Content-Type: application/octet-stream).
 * En-tête obligatoire : X-Content-SHA256 (empreinte SHA-256 du contenu, en hexadécimal).
 * Le fichier n'est enregistré que si l'empreinte correspond.
 *
 * Réponses :
 *   201 {"status":"stored", "complete":true, "missing":[], …}   pièce enregistrée
 *   200 {"status":"already-stored", …}                          déjà reçue (même empreinte)
 *   404 message ou pièce inconnus (le relais renvoie alors le message), y compris quand le mail
 *       est supprimé pendant l'envoi : le fichier reçu est alors effacé
 *   409 pièce ignorée car trop volumineuse (too_large), ou déjà reçue avec une autre empreinte
 *   413 contenu au-delà de la limite · 415 mauvais Content-Type · 422 empreinte absente ou fausse
 */
final readonly class UploadPartAction
{
    public function __construct(
        private MessageRepository $repository,
        private FileStore $files,
        private ResponseFactoryInterface $responseFactory,
        private int $maxPartSize,
    ) {
    }

    /** @param array{id: string, partId: string} $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'];
        $partId = $args['partId'];
        if (!preg_match(Message::ID_PATTERN, $id) || !preg_match(Part::ID_PATTERN, $partId)) {
            return $this->error(404, 'pièce inconnue');
        }

        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType !== 'application/octet-stream') {
            return $this->error(415, 'Content-Type application/octet-stream attendu');
        }

        $expected = strtolower(trim($request->getHeaderLine('X-Content-SHA256')));
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)) {
            return $this->error(422, 'en-tête X-Content-SHA256 absent ou invalide');
        }

        $part = $this->repository->findPart($id, $partId);
        if ($part === null) {
            return $this->error(404, 'pièce inconnue : envoyez d\'abord le message (POST /v1/messages)');
        }

        if ($part['status'] === 'too_large') {
            return $this->error(409, 'pièce trop volumineuse : elle n\'est pas enregistrée');
        }
        if ($part['status'] === 'stored') {
            if ($part['sha256'] !== $expected) {
                return $this->error(409, 'pièce déjà reçue avec un contenu différent');
            }

            return $this->ok(200, 'already-stored', $id, $partId, $this->repository->completion($part['message_pk']));
        }

        $limit = $this->maxPartSize;
        $declaredLength = $request->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && (int) $declaredLength > $limit) {
            return $this->error(413, sprintf('contenu au-delà de la limite (%d octets)', $limit));
        }

        $upload = $this->files->receive($request->getBody(), $limit);
        if ($upload === null) {
            return $this->error(413, sprintf('contenu au-delà de la limite (%d octets)', $limit));
        }
        if (!hash_equals($expected, $upload['sha256'])) {
            $this->files->discard($upload['tmp']);
            error_log(sprintf('[parts] %s/%s : empreinte différente (contenu abîmé en route ?)', $id, $partId));

            return $this->error(422, 'empreinte SHA-256 différente du contenu reçu');
        }

        $path = $this->files->commit($upload['tmp'], FileStore::partPath($id, $partId));
        $state = $this->repository->markPartStored($part['message_pk'], $partId, $upload['size'], $upload['sha256'], $path);
        if ($state === null) {
            // Mail supprimé pendant l'envoi (purge, Dashboard, API) : le fichier ne serait rattaché
            // à aucune ligne, et personne ne l'effacerait.
            error_log(sprintf(
                '[parts] %s/%s : mail supprimé pendant l\'envoi de la pièce, %s',
                $id,
                $partId,
                $this->files->deletePart($path) ? 'fichier effacé' : 'fichier pas effacé (le nettoyage le retirera)',
            ));

            return $this->error(404, 'pièce inconnue : le mail a été supprimé pendant l\'envoi');
        }

        return $this->ok(201, 'stored', $id, $partId, $state);
    }

    /** @param array{complete: bool, missing: list<string>, too_large: list<string>} $state */
    private function ok(int $code, string $status, string $id, string $partId, array $state): ResponseInterface
    {
        return Json::response(
            $this->responseFactory,
            $code,
            ['status' => $status, 'id' => $id, 'part' => $partId] + $state,
        );
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return Json::response($this->responseFactory, $status, ['error' => $message]);
    }
}
