<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Dashboard\Session;
use App\Dashboard\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Commun à toutes les pages du Dashboard (/v1/app) :
 *  - démarre la session (cookie Secure si la page est en HTTPS) ;
 *  - refuse tout formulaire POST sans le bon jeton anti-CSRF (champ « _csrf ») ;
 *  - ajoute les en-têtes de sécurité (pas de cache, pas d'iframe, pas de script).
 */
final readonly class DashboardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Session $session,
        private View $view,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start($request->getUri()->getScheme() === 'https');

        $response = null;
        if ($request->getMethod() === 'POST') {
            $body = $request->getParsedBody();
            if (!$this->session->checkCsrf(is_array($body) ? ($body['_csrf'] ?? null) : null)) {
                $this->session->flash('message', ['type' => 'error', 'message' => 'Formulaire expiré : recommencez.']);
                $response = $this->view->redirect($this->session->adminId() === null ? '/v1/app/signin' : '/v1/app/messages');
            }
        }

        $response ??= $handler->handle($request);

        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin');
    }
}
