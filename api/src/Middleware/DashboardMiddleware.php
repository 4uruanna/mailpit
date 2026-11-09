<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Dashboard\SecurityHeaders;
use App\Dashboard\Session;
use App\Dashboard\View;
use App\Http\HttpsDetector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Commun à toutes les pages du Dashboard (/v1/app) :
 *  - démarre la session (cookie Secure si la page est en HTTPS, même détection que
 *    ForceHttpsMiddleware : derrière un proxy de confiance, X-Forwarded-Proto). Un GET avec
 *    ?auto=1 (rechargement automatique de la page Statut) ne compte pas comme une activité ;
 *  - refuse tout formulaire POST sans le bon jeton anti-CSRF (champ « _csrf ») ;
 *  - ajoute les en-têtes de sécurité (SecurityHeaders : pas de cache, pas d'iframe, seul le script
 *    dashboard.js), sauf ceux que la page a déjà fixés.
 */
final readonly class DashboardMiddleware implements MiddlewareInterface
{
    /** Paramètre des rechargements automatiques (?auto=1), qui ne prolongent pas la session. */
    public const string AUTO_REFRESH = 'auto';

    public function __construct(
        private Session $session,
        private View $view,
        private HttpsDetector $https,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $automatic = $request->getMethod() === 'GET' && ($request->getQueryParams()[self::AUTO_REFRESH] ?? null) === '1';
        $this->session->start($this->https->isHttps($request), touch: !$automatic);

        $response = null;
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            if (!$this->session->checkCsrf(is_array($body) ? ($body['_csrf'] ?? null) : null)) {
                $this->session->flash('message', ['type' => 'error', 'message' => 'Formulaire expiré : recommencez.']);
                $response = $this->view->redirect($this->session->adminId() === null ? '/v1/app/signin' : '/v1/app/messages');
            }
        }

        $response ??= $handler->handle($request);

        // Une page peut fixer ses propres règles (ex. le corps HTML d'un mail, affiché dans une iframe).
        // Les pages d'erreur reçoivent les mêmes en-têtes (App\Dashboard\ErrorPage).
        return SecurityHeaders::apply($response);
    }
}
