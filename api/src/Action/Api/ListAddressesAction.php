<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Auth\ApiScope;
use App\Http\Json;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/addresses : adresses associées au jeton, avec le nombre de mails reçus par chacune.
 *
 * Réponse :
 *   200 {"count": 2, "addresses": [{"address": "alice@test.mondomaine.fr", "messages": 3}, …]}
 */
final readonly class ListAddressesAction
{
    public function __construct(
        private MessageRepository $repository,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var ApiScope $scope */
        $scope = $request->getAttribute(ApiScope::ATTRIBUTE);

        $addresses = [];
        foreach ($this->repository->countByAddress($scope->addresses ?? []) as $address => $count) {
            $addresses[] = ['address' => (string) $address, 'messages' => $count];
        }

        return Json::response($this->responseFactory, 200, ['count' => count($addresses), 'addresses' => $addresses]);
    }
}
