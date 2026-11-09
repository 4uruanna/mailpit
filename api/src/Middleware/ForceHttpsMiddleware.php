<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\ClientIpResolver;
use App\Http\HttpsDetector;
use App\Http\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTPS obligatoire, pour toutes les routes (API et Dashboard).
 *
 * Filet de sécurité : le .htaccess redirige déjà vers HTTPS, mais seulement si mod_rewrite
 * est actif. Une requête en clair (voir HttpsDetector) reçoit :
 *  - GET / HEAD : une redirection 301 vers la même URL en https:// ;
 *  - toute autre méthode : 403, sans lire le corps. Rediriger un POST le ferait renvoyer
 *    en clair une seconde fois par certains clients ; mieux vaut une erreur visible.
 *
 * Les réponses HTTPS reçoivent l'en-tête Strict-Transport-Security : le navigateur
 * n'essaiera plus HTTP pendant un an.
 *
 * Désactivable en développement local : 'force_https' => false dans config.php.
 */
final readonly class ForceHttpsMiddleware implements MiddlewareInterface
{
    /** Un an, sans « preload » (inscription dans les navigateurs, difficile à annuler). */
    public const string HSTS = 'max-age=31536000';

    public function __construct(
        private HttpsDetector $detector,
        private ClientIpResolver $ipResolver,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->detector->isHttps($request)) {
            $response = $handler->handle($request);

            return $response->hasHeader('Strict-Transport-Security')
                ? $response
                : $response->withHeader('Strict-Transport-Security', self::HSTS);
        }

        $method = $request->getMethod();
        $uri = $request->getUri();
        // Un jeton envoyé en clair a pu être lu en chemin : on le signale, sans sa valeur.
        $credentials = $request->hasHeader('Authorization') ? ' ; en-tête Authorization envoyé en clair : changez ce jeton' : '';

        if (($method === 'GET' || $method === 'HEAD') && $uri->getHost() !== '') {
            if ($credentials !== '') {
                error_log(sprintf('[https] %s %s en http:// depuis « %s », redirigé%s', $method, $uri->getPath(), $this->ipResolver->resolve($request), $credentials));
            }

            return $this->responseFactory->createResponse(301)
                ->withHeader('Location', (string) $uri->withScheme('https')->withPort(null));
        }

        error_log(sprintf('[https] requête en clair refusée depuis « %s » : %s %s%s', $this->ipResolver->resolve($request), $method, $uri->getPath(), $credentials));

        return Json::response($this->responseFactory, 403, ['error' => 'HTTPS obligatoire : renvoyez la requête en https://']);
    }
}
