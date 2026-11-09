<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Dashboard\Session;
use App\Dashboard\View;
use App\Persistence\AdminRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Pages réservées aux administrateurs connectés : sinon, redirection vers /v1/app/signin.
 * Un administrateur supprimé de la table mailpit_admins est déconnecté à sa requête suivante.
 */
final readonly class RequireAdminMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Session $session,
        private AdminRepository $admins,
        private View $view,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $this->session->adminId();
        if ($id === null || $this->admins->find($id) === null) {
            $this->session->logout();

            return $this->view->redirect('/v1/app/signin');
        }

        return $handler->handle($request);
    }
}
