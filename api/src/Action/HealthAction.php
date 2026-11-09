<?php

declare(strict_types=1);

namespace App\Action;

use App\Http\Json;
use App\Middleware\IpFilterMiddleware;
use App\Persistence\HealthRepository;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v1/health : ping de santé envoyé par le relais toutes les 30 secondes (seul autorisé, voir
 * TokenAuthMiddleware). Alimente la page « Statut » du Dashboard.
 *
 * Corps JSON (chaque champ peut être absent ou null) :
 *   {"latency_ms": 85, "pending": 0, "failed": 2}
 *   latency_ms  aller-retour HTTP du ping PRÉCÉDENT, mesuré par le relais (millisecondes)
 *   pending     mails en attente dans Mailpit (null : Mailpit injoignable)
 *   failed      mails refusés par l'API (tag relay-failed dans Mailpit)
 *
 * Réponses :
 *   200 {"status":"ok", "time":"2026-11-09T08:31:20.474Z"}   ping enregistré (heure de l'API, UTC)
 *   400 / 413 / 415 / 422                                     requête invalide
 *
 * À chaque ping, les pings de plus de 48 heures sont supprimés. Le nettoyage des mails
 * (MaintenanceMiddleware) tourne aussi après un ping : la purge a lieu même sans nouveau mail.
 */
final readonly class HealthAction
{
    /** Taille maximale du corps (octets) : quelques champs suffisent. */
    public const int MAX_BODY_SIZE = 4096;

    /** Valeurs maximales acceptées : une heure de latence, un milliard de mails. */
    private const array LIMITS = [
        'latency_ms' => 3_600_000,
        'pending'    => 1_000_000_000,
        'failed'     => 1_000_000_000,
    ];

    public function __construct(
        private HealthRepository $repository,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType !== 'application/json') {
            return $this->error(415, 'Content-Type application/json attendu');
        }

        $declaredLength = $request->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && (int) $declaredLength > self::MAX_BODY_SIZE) {
            return $this->error(413, 'corps de requête trop volumineux');
        }

        // Lecture bornée : jamais plus de MAX_BODY_SIZE + 1 octets.
        $body = $request->getBody();
        $json = '';
        while (!$body->eof() && strlen($json) <= self::MAX_BODY_SIZE) {
            $chunk = $body->read(self::MAX_BODY_SIZE + 1 - strlen($json));
            if ($chunk === '') {
                break;
            }
            $json .= $chunk;
        }
        if (strlen($json) > self::MAX_BODY_SIZE) {
            return $this->error(413, 'corps de requête trop volumineux');
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(400, 'JSON invalide');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return $this->error(422, 'objet JSON attendu : {"latency_ms": …, "pending": …, "failed": …}');
        }

        // Champs connus validés ; les autres sont ignorés (relais plus récent que l'API).
        $values = [];
        foreach (self::LIMITS as $field => $max) {
            $value = $data[$field] ?? null;
            if ($value !== null && (!is_int($value) || $value < 0 || $value > $max)) {
                return $this->error(422, sprintf('« %s » doit être un entier de 0 à %d, ou null', $field, $max));
            }
            $values[$field] = $value;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $label = $request->getHeaderLine('Mailpit-Label');
        $this->repository->record(
            $now,
            $values['latency_ms'],
            $values['pending'],
            $values['failed'],
            (string) $request->getAttribute(IpFilterMiddleware::ATTRIBUTE, ''),
            $label === '' ? null : $label,
        );
        $this->repository->purgeBefore($now->modify(sprintf('-%d seconds', HealthRepository::RETENTION)));

        return Json::response($this->responseFactory, 200, [
            'status' => 'ok',
            'time'   => $now->format('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return Json::response($this->responseFactory, $status, ['error' => $message]);
    }
}
