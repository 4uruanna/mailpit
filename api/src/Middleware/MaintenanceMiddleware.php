<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Persistence\Maintenance;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lance le nettoyage (Maintenance::runIfDue, au plus toutes les 15 minutes) après une requête du
 * relais : l'API n'a besoin d'aucune tâche planifiée. Placé après le filtre IP et le jeton, il ne
 * tourne que pour les requêtes acceptées.
 *
 * La réponse est déjà calculée et n'est pas modifiée : une erreur de nettoyage est seulement
 * écrite dans le log, jamais renvoyée au relais.
 */
final readonly class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(private Maintenance $maintenance)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $this->maintenance->runIfDue();

        return $response;
    }
}
