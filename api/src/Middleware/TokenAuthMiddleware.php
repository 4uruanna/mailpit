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
 * Le relais l'envoie dans l'en-tête « Authorization: Bearer <jeton> ». C'est le SEUL endroit
 * accepté : un jeton dans l'URL (?token=…) apparaîtrait dans les logs d'accès de l'hébergement.
 * Un ?token=… est donc ignoré (401 s'il n'y a pas d'en-tête valide), avec un avertissement
 * dans le log, qui ne contient jamais sa valeur. Le webhook direct de Mailpit, qui ne sait pas
 * ajouter d'en-tête, ne peut donc pas alimenter l'API : il faut passer par le relais.
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
        $this->warnAboutQueryToken($request);
        $given = self::bearer($request);

        if ($given === null || !hash_equals($this->token, $given)) {
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

    /**
     * Avertit, sans jamais écrire sa valeur, qu'un jeton a été passé dans l'URL : il est ignoré.
     * S'il s'agit du vrai jeton, il figure désormais dans les logs d'accès : il faut le changer.
     */
    private function warnAboutQueryToken(ServerRequestInterface $request): void
    {
        $params = $request->getQueryParams();
        if (!array_key_exists('token', $params)) {
            return;
        }

        $leaked = is_string($params['token']) && hash_equals($this->token, $params['token']);
        error_log(sprintf(
            '[auth] %s dans l\'URL (?token=…), ignoré : seul l\'en-tête « Authorization: Bearer … » est accepté : %s %s%s',
            $leaked ? 'jeton du relais' : 'jeton',
            $request->getMethod(),
            $request->getUri()->getPath(),
            $leaked ? '. Il figure maintenant dans les logs d\'accès de l\'hébergement : changez-le' : '',
        ));
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
