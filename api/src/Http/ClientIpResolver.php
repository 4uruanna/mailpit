<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Détermine l'IP réelle du client.
 *
 * Par défaut c'est REMOTE_ADDR. L'en-tête de proxy (X-Forwarded-For…) n'est
 * pris en compte que si la requête vient d'un proxy de confiance : sinon
 * n'importe qui pourrait l'envoyer pour se faire passer pour le serveur Mailpit.
 */
final readonly class ClientIpResolver
{
    public function __construct(
        private IpMatcher $trustedProxies,
        private string $header = 'X-Forwarded-For',
    ) {
    }

    public function resolve(ServerRequestInterface $request): string
    {
        $remoteAddr = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        if (!$this->trustedProxies->matches($remoteAddr) || !$request->hasHeader($this->header)) {
            return $remoteAddr;
        }

        // On remonte la chaîne de droite à gauche : la première IP qui n'est pas
        // un proxy de confiance est celle du client.
        $chain = array_map('trim', explode(',', $request->getHeaderLine($this->header)));
        foreach (array_reverse($chain) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                // Valeur illisible : on ne peut pas aller plus loin de façon fiable.
                return $remoteAddr;
            }
            if (!$this->trustedProxies->matches($ip)) {
                return $ip;
            }
        }

        return $remoteAddr;
    }

    /**
     * La requête arrive-t-elle directement d'un proxy de confiance (REMOTE_ADDR) ?
     * Seuls ces proxys peuvent fixer les en-têtes X-Forwarded-* lus par l'API.
     */
    public function fromTrustedProxy(ServerRequestInterface $request): bool
    {
        return $this->trustedProxies->matches((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
    }
}
