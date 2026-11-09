<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Auth\ApiScope;
use App\Http\Json;
use App\Mailpit\Address;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/messages : mails des adresses associées au jeton, du plus récent au plus ancien,
 * avec leur corps (texte et HTML) et la description de leurs pièces jointes.
 *
 * Paramètres facultatifs :
 *   address  une seule des adresses du jeton (sinon : toutes ses adresses)
 *   limit    nombre de mails, 5 par défaut, 25 au maximum
 *   offset   nombre de mails à sauter (0 par défaut)
 *   page     numéro de page (à partir de 1), à la place d'offset : offset = (page - 1) × limit
 *
 * Avec le jeton du relais (depuis les IP autorisées), tous les mails sont visibles.
 *
 * Réponse :
 *   200 {"total": 12, "count": 5, "limit": 5, "offset": 0, "page": 1, "messages": [ {…, "text": "…", "html": "…"} ]}
 *   400 paramètre invalide · 403 adresse non associée au jeton
 */
final readonly class ListMessagesAction
{
    public const int DEFAULT_LIMIT = 5;
    public const int MAX_LIMIT = 25;

    public function __construct(
        private MessageRepository $repository,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var ApiScope $scope */
        $scope = $request->getAttribute(ApiScope::ATTRIBUTE);
        $query = $request->getQueryParams();

        $limit = self::integer($query['limit'] ?? null, self::DEFAULT_LIMIT);
        if ($limit === null || $limit < 1 || $limit > self::MAX_LIMIT) {
            return $this->error(400, sprintf('« limit » doit être compris entre 1 et %d', self::MAX_LIMIT));
        }

        $hasOffset = ($query['offset'] ?? '') !== '';
        $hasPage = ($query['page'] ?? '') !== '';
        if ($hasOffset && $hasPage) {
            return $this->error(400, 'utilisez « offset » ou « page », pas les deux');
        }

        if ($hasPage) {
            $page = self::integer($query['page'], 1);
            if ($page === null || $page < 1) {
                return $this->error(400, '« page » doit être un entier à partir de 1');
            }
            $offset = ($page - 1) * $limit;
        } else {
            $offset = self::integer($query['offset'] ?? null, 0);
            if ($offset === null) {
                return $this->error(400, '« offset » doit être un entier positif');
            }
        }

        $addresses = $scope->addresses;
        if (array_key_exists('address', $query)) {
            $address = is_string($query['address']) ? Address::normalize($query['address']) : '';
            if ($address === '') {
                return $this->error(400, '« address » est vide');
            }
            if (!$scope->allows($address)) {
                return $this->error(403, sprintf('l\'adresse « %s » n\'est pas associée à ce jeton', $address));
            }
            $addresses = [$address];
        }

        $result = $this->repository->list($limit, $offset, $addresses, withBodies: true);

        return Json::response($this->responseFactory, 200, [
            'total'    => $result['total'],
            'count'    => count($result['messages']),
            'limit'    => $limit,
            'offset'   => $offset,
            'page'     => intdiv($offset, $limit) + 1,
            'messages' => $result['messages'],
        ]);
    }

    /** Entier positif lu dans la requête, $default si absent, null si invalide. */
    private static function integer(mixed $value, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return is_string($value) && ctype_digit($value) && strlen($value) <= 9 ? (int) $value : null;
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return Json::response($this->responseFactory, $status, ['error' => $message]);
    }
}
