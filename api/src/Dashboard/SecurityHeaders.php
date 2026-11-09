<?php

declare(strict_types=1);

namespace App\Dashboard;

use Psr\Http\Message\ResponseInterface;

/**
 * En-têtes de sécurité de toutes les réponses du Dashboard (pages, redirections, pages d'erreur) :
 * pas de cache, pas d'iframe, seul le script dashboard.js, pas de devinette du type de contenu.
 */
final class SecurityHeaders
{
    /** @var array<string, string> */
    public const array HEADERS = [
        'Cache-Control'           => 'no-store',
        'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; img-src 'self' data:; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        'X-Frame-Options'         => 'DENY',
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'same-origin',
    ];

    /**
     * Ajoute les en-têtes, sauf ceux que la réponse a déjà fixés
     * (ex. le corps HTML d'un mail, affiché dans une iframe, a sa propre CSP).
     */
    public static function apply(ResponseInterface $response): ResponseInterface
    {
        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
