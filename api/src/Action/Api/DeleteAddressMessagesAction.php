<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Auth\ApiScope;
use App\Http\Json;
use App\Mailpit\Address;
use App\Persistence\FileStore;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v1/addresses?address=… : supprime tous les mails reçus par cette adresse
 * (en base et leurs pièces jointes sur le disque). L'adresse reste associée au jeton.
 *
 * Un mail envoyé à plusieurs adresses est supprimé pour toutes. Les mails restent dans Mailpit.
 *
 * Réponses :
 *   200 {"address": "alice@test.mondomaine.fr", "deleted": 3}
 *   400 « address » absent · 403 adresse non associée au jeton
 */
final readonly class DeleteAddressMessagesAction
{
    public function __construct(
        private MessageRepository $repository,
        private FileStore $files,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var ApiScope $scope */
        $scope = $request->getAttribute(ApiScope::ATTRIBUTE);

        $value = $request->getQueryParams()['address'] ?? null;
        $address = is_string($value) ? Address::normalize($value) : '';
        if ($address === '') {
            return Json::response($this->responseFactory, 400, ['error' => 'paramètre « address » obligatoire']);
        }
        if (!$scope->allows($address)) {
            return Json::response($this->responseFactory, 403, ['error' => sprintf('l\'adresse « %s » n\'est pas associée à ce jeton', $address)]);
        }

        $deleted = $this->repository->deleteByAddress($address);
        foreach ($deleted as $mailpitId) {
            if (!$this->files->deleteMessage($mailpitId)) {
                error_log(sprintf('[delete] fichiers de %s pas tous effacés', $mailpitId));
            }
        }

        return Json::response($this->responseFactory, 200, ['address' => $address, 'deleted' => count($deleted)]);
    }
}
