<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Auth\ApiScope;
use App\Http\Json;
use App\Mailpit\Message;
use App\Persistence\FileStore;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/messages/{id} : supprime un mail (en base et ses pièces jointes sur le disque),
 * s'il a été reçu par l'une des adresses du jeton. Le mail reste dans Mailpit.
 *
 * Réponses :
 *   200 {"id": "…", "deleted": true}
 *   404 mail inconnu, ou reçu par aucune des adresses du jeton
 */
final readonly class DeleteMessageAction
{
    public function __construct(
        private MessageRepository $repository,
        private FileStore $files,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    /** @param array{id: string} $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var ApiScope $scope */
        $scope = $request->getAttribute(ApiScope::ATTRIBUTE);
        $id = $args['id'];

        if (!preg_match(Message::ID_PATTERN, $id) || !$this->repository->delete($id, $scope->addresses)) {
            return Json::response($this->responseFactory, 404, ['error' => 'mail inconnu']);
        }

        if (!$this->files->deleteMessage($id)) {
            error_log(sprintf('[delete] fichiers de %s pas tous effacés', $id));
        }

        return Json::response($this->responseFactory, 200, ['id' => $id, 'deleted' => true]);
    }
}
