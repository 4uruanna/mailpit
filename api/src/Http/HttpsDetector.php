<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Indique si le client a envoyé la requête en HTTPS.
 *
 * Signes d'une requête HTTPS, vus par PHP :
 *  - HTTPS=on (variable du serveur web), ou une URL en https:// ;
 *  - le port 443 (SERVER_PORT) : c'est la méthode fiable chez OVH, dont le frontal
 *    ne fixe pas toujours HTTPS.
 *
 * Derrière un proxy de confiance (proxy.trusted_proxies), c'est l'en-tête X-Forwarded-Proto
 * qui décide : le proxy peut parler en HTTP à l'hébergement alors que le client est en HTTPS,
 * ou l'inverse. Il n'est lu QUE pour les requêtes qui viennent de ces proxys : sinon, n'importe
 * qui pourrait l'envoyer pour faire passer une requête en clair pour une requête HTTPS.
 */
final readonly class HttpsDetector
{
    public const string PROTO_HEADER = 'X-Forwarded-Proto';

    public function __construct(
        private ClientIpResolver $resolver,
    ) {
    }

    public function isHttps(ServerRequestInterface $request): bool
    {
        if ($this->resolver->fromTrustedProxy($request) && $request->hasHeader(self::PROTO_HEADER)) {
            // « https, http » si plusieurs proxys l'ont complété : la dernière valeur est celle
            // du proxy de confiance, la seule qu'un client ne peut pas falsifier.
            $values = explode(',', $request->getHeaderLine(self::PROTO_HEADER));

            return strtolower(trim((string) end($values))) === 'https';
        }

        $server = $request->getServerParams();
        $https = strtolower((string) ($server['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off')
            || (string) ($server['SERVER_PORT'] ?? '') === '443'
            || $request->getUri()->getScheme() === 'https';
    }
}
