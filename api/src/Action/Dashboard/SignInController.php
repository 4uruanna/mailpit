<?php

declare(strict_types=1);

namespace App\Action\Dashboard;

use App\Auth\LoginThrottle;
use App\Dashboard\Session;
use App\Dashboard\View;
use App\Http\ClientIpResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET  /v1/app/signin   formulaire de connexion
 * POST /v1/app/signin   connexion (3 échecs au plus par identifiant sur 30 minutes)
 * POST /v1/app/signout  déconnexion
 */
final readonly class SignInController
{
    public function __construct(
        private LoginThrottle $throttle,
        private Session $session,
        private View $view,
        private ClientIpResolver $ipResolver,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->session->adminId() !== null) {
            return $this->view->redirect('/v1/app/messages');
        }

        return $this->view->render('signin', ['username' => ''], title: 'Connexion');
    }

    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        if ($username === '' || $password === '' || mb_strlen($username) > 255 || strlen($password) > 4096) {
            return $this->failure($username, 'Saisissez votre identifiant et votre mot de passe.', 422);
        }

        $result = $this->throttle->attempt($username, $password, $this->ipResolver->resolve($request));

        return match ($result['status']) {
            'ok'      => $this->success($result['admin']),
            'blocked' => $this->failure(
                $username,
                sprintf('Trop de tentatives pour cet identifiant. Réessayez dans %d minute(s).', (int) ceil($result['retry_after'] / 60)),
                429,
            )->withHeader('Retry-After', (string) $result['retry_after']),
            default   => $this->failure(
                $username,
                $result['remaining'] > 0
                    ? sprintf('Identifiant ou mot de passe incorrect. Encore %d essai(s) avant blocage de 30 minutes.', $result['remaining'])
                    : 'Identifiant ou mot de passe incorrect. Identifiant bloqué pour 30 minutes.',
                401,
            ),
        };
    }

    public function signOut(ServerRequestInterface $request): ResponseInterface
    {
        $this->session->logout();
        $this->session->flash('message', ['type' => 'success', 'message' => 'Vous êtes déconnecté.']);

        return $this->view->redirect('/v1/app/signin');
    }

    /** @param array{id: int, username: string} $admin */
    private function success(array $admin): ResponseInterface
    {
        $this->session->login($admin['id'], $admin['username']);

        return $this->view->redirect('/v1/app/messages');
    }

    private function failure(string $username, string $message, int $status): ResponseInterface
    {
        return $this->view->render(
            'signin',
            ['username' => $username, 'flash' => ['type' => 'error', 'message' => $message]],
            $status,
            'Connexion',
        );
    }
}
