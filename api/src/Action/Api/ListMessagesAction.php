<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Auth\ApiScope;
use App\Http\Json;
use App\Http\WaitClock;
use App\Mailpit\Address;
use App\Persistence\MessageRepository;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/messages : mails des adresses associées au jeton, du plus récent au plus ancien.
 * Résumés seulement (adresses, sujet, extrait, dates, description des pièces) : ni corps ni
 * en-têtes, qui peuvent peser jusqu'à 16 Mo chacun. Le mail complet : GET /v1/messages/{id}.
 *
 * Paramètres facultatifs :
 *   address  une seule des adresses du jeton (sinon : toutes ses adresses)
 *   limit    nombre de mails, 5 par défaut, 25 au maximum
 *   offset   nombre de mails à sauter (0 par défaut)
 *   page     numéro de page (à partir de 1), à la place d'offset : offset = (page - 1) × limit
 *   since    date ISO 8601 avec fuseau (« 2026-11-09T14:03:00Z ») : seulement les mails reçus par
 *            Mailpit strictement après (colonne created_at, à la milliseconde près)
 *   wait     1 à 60 secondes : attend qu'au moins un mail COMPLET (toutes ses pièces jointes reçues)
 *            corresponde à address et since. Vérification chaque seconde (SELECT 1 … LIMIT 1), puis
 *            une seule requête de liste, qui ne garde elle aussi que les mails complets.
 *
 * Avec le jeton du relais (depuis les IP autorisées), tous les mails sont visibles.
 *
 * Réponse :
 *   200 {"total": 12, "count": 5, "limit": 5, "offset": 0, "page": 1, "messages": [ {"id": "…", "snippet": "…", …} ]}
 *       avec wait : en plus "waited_ms" (durée de l'attente) ; "count": 0 si rien n'est arrivé à temps
 *   400 paramètre invalide · 403 adresse non associée au jeton
 */
final readonly class ListMessagesAction
{
    public const int DEFAULT_LIMIT = 5;
    public const int MAX_LIMIT = 25;
    /** Attente maximale (?wait=), en secondes. */
    public const int MAX_WAIT = 60;
    /** Intervalle entre deux vérifications pendant l'attente, en secondes. */
    public const float POLL_INTERVAL = 1.0;
    /** Marge ajoutée à l'attente pour la limite de durée de PHP (max_execution_time), en secondes. */
    public const int TIME_LIMIT_MARGIN = 30;

    /** Date ISO 8601 avec fuseau : 2026-11-09T14:03:00Z, 2026-11-09T15:03:00.123+01:00, 2026-11-09T14:03Z… */
    private const string ISO_DATE = '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,9})?)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)$/i';

    public function __construct(
        private MessageRepository $repository,
        private ResponseFactoryInterface $responseFactory,
        private WaitClock $clock,
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

        $since = null;
        if (($query['since'] ?? '') !== '') {
            $since = self::date($query['since']);
            if ($since === null) {
                return $this->error(400, '« since » doit être une date ISO 8601 avec fuseau, par exemple 2026-11-09T14:03:00Z');
            }
        }

        $wait = 0;
        if (($query['wait'] ?? '') !== '') {
            $wait = self::integer($query['wait'], 0);
            if ($wait === null || $wait < 1 || $wait > self::MAX_WAIT) {
                return $this->error(400, sprintf('« wait » doit être un nombre de secondes entre 1 et %d', self::MAX_WAIT));
            }
        }

        // Avec wait, seuls les mails complets comptent, pendant l'attente comme dans la réponse :
        // le développeur reçoit un mail exploitable, pièces jointes comprises.
        // Jeton sans adresse : aucun mail ne peut arriver, inutile d'attendre.
        $completeOnly = $wait > 0;
        $waitedMs = match (true) {
            $wait === 0       => null,
            $addresses === [] => 0,
            default           => $this->waitForMessage($wait, $addresses, $since),
        };

        $result = $this->repository->list($limit, $offset, $addresses, since: $since, completeOnly: $completeOnly);

        return Json::response($this->responseFactory, 200, [
            'total'    => $result['total'],
            'count'    => count($result['messages']),
            'limit'    => $limit,
            'offset'   => $offset,
            'page'     => intdiv($offset, $limit) + 1,
        ] + ($waitedMs === null ? [] : ['waited_ms' => $waitedMs]) + [
            'messages' => $result['messages'],
        ]);
    }

    /**
     * Attend jusqu'à $wait secondes qu'au moins un mail complet corresponde aux filtres : une requête
     * légère (SELECT 1 … LIMIT 1) toutes les POLL_INTERVAL secondes, sur la même connexion MySQL.
     * S'arrête plus tôt si le client est parti (quand PHP peut le savoir).
     *
     * @param list<string>|null $addresses
     * @return int durée de l'attente, en millisecondes
     */
    private function waitForMessage(int $wait, ?array $addresses, ?DateTimeImmutable $since): int
    {
        // set_time_limit peut être désactivée par l'hébergement (disable_functions).
        $limit = self::timeLimit($wait, (int) ini_get('max_execution_time'));
        if ($limit !== null && function_exists('set_time_limit')) {
            set_time_limit($limit);
        }

        $start = $this->clock->now();
        $deadline = $start + $wait;
        while (!$this->repository->exists($addresses, $since, completeOnly: true)) {
            $remaining = $deadline - $this->clock->now();
            if ($remaining <= 0 || $this->clock->clientGone()) {
                break;
            }
            $this->clock->sleep(min(self::POLL_INTERVAL, $remaining));
        }

        return max(0, (int) round(($this->clock->now() - $start) * 1000));
    }

    /**
     * Nouvelle limite de durée de PHP pour une attente de $wait secondes, ou null pour ne rien
     * changer. Sans limite (0, comme en ligne de commande) : rien. Sinon, au moins wait + marge à
     * partir de maintenant (set_time_limit repart de zéro), sans jamais réduire la limite actuelle.
     */
    public static function timeLimit(int $wait, int $current): ?int
    {
        if ($current <= 0) {
            return null;
        }

        return max($current, $wait + self::TIME_LIMIT_MARGIN);
    }

    /**
     * Date ISO 8601 avec fuseau lue dans la requête, null si invalide. Un « + » non encodé dans
     * l'URL devient une espace : « 2026-11-09T15:03:00 01:00 » est lu comme « …+01:00 ».
     */
    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $value = (string) preg_replace('/ (?=\d{2}:?\d{2}$)/', '+', $value);
        if (preg_match(self::ISO_DATE, $value, $match) !== 1 || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        try {
            $date = new DateTimeImmutable(strtoupper($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        // Bornes de la colonne DATETIME de MySQL, une fois la date ramenée en UTC.
        $year = (int) $date->format('Y');

        return $year >= 1000 && $year <= 9998 ? $date : null;
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
