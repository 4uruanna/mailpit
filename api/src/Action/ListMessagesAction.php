<?php

declare(strict_types=1);

namespace App\Action;

use App\Http\Json;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/messages : liste des messages enregistrés, du plus récent au plus ancien.
 *
 * Paramètres facultatifs : limit (1 à 1000, 1000 par défaut) et offset (0 par défaut).
 * Avec la rétention par défaut (webhook.max_messages = 1000), une seule page contient tout.
 *
 * Réponse :
 *   200 {"total": 42, "count": 42, "limit": 1000, "offset": 0, "messages": [
 *         {"id": "…", "subject": "…", "from": {…}, "to": [ … ], …, "complete": true,
 *          "parts": [{"part_id": "2", "kind": "attachment", "file_name": "devis.pdf", …, "status": "stored"}]}
 *       ]}
 *   400 limit ou offset invalide
 */
final readonly class ListMessagesAction
{
    public const int MAX_LIMIT = 1000;

    public function __construct(
        private MessageRepository $repository,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $limit = self::integer($query['limit'] ?? null, self::MAX_LIMIT);
        $offset = self::integer($query['offset'] ?? null, 0);
        if ($limit === null || $limit < 1 || $limit > self::MAX_LIMIT) {
            return Json::response($this->responseFactory, 400, ['error' => sprintf('« limit » doit être compris entre 1 et %d', self::MAX_LIMIT)]);
        }
        if ($offset === null) {
            return Json::response($this->responseFactory, 400, ['error' => '« offset » doit être un entier positif']);
        }

        $result = $this->repository->list($limit, $offset);

        return Json::response($this->responseFactory, 200, [
            'total'    => $result['total'],
            'count'    => count($result['messages']),
            'limit'    => $limit,
            'offset'   => $offset,
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
}
