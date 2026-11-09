<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Vérifie le jeton partagé avec le relais (webhook.token dans config.php, RELAY_API_TOKEN côté relais).
 *
 * Le relais l'envoie dans l'en-tête « Authorization: Bearer <jeton> ».
 * Le webhook direct de Mailpit ne sait pas ajouter d'en-tête : pour lui, le jeton
 * peut aussi être passé dans l'URL (?token=…), au prix de le voir apparaître dans
 * les logs d'accès de l'hébergement.
 *
 * Réponse 401 si le jeton est absent ou faux. Le contrôle a lieu avant toute lecture du corps.
 */
final readonly class TokenAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $token,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $given = self::bearer($request) ?? $request->getQueryParams()['token'] ?? null;

        if (!is_string($given) || $given === '' || !hash_equals($this->token, $given)) {
            error_log(sprintf(
                '[auth] jeton %s : %s %s',
                $given === null ? 'absent' : 'invalide',
                $request->getMethod(),
                $request->getUri()->getPath(),
            ));

            return Json::response($this->responseFactory, 401, ['error' => 'Unauthorized'])
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        return $handler->handle($request);
    }

    /** Jeton de l'en-tête « Authorization: Bearer … », ou null. */
    public static function bearer(ServerRequestInterface $request): ?string
    {
        // Sur un hébergement Apache + PHP-FPM (OVH), l'en-tête Authorization n'arrive pas
        // toujours tel quel : le .htaccess le recopie dans HTTP_AUTHORIZATION.
        $server = $request->getServerParams();
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
