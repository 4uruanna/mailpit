<?php

declare(strict_types=1);

namespace App\Action\Dashboard;

use App\Dashboard\Session;
use App\Dashboard\View;
use App\Mailpit\Address;
use App\Persistence\MessageRepository;
use App\Persistence\TokenRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * GET  /v1/app/tokens                          liste des jetons + formulaire de création
 * POST /v1/app/tokens                          crée un jeton (affiché une seule fois)
 * POST /v1/app/tokens/{id}/delete              supprime un jeton
 * GET  /v1/app/tokens/{id}                     détail : adresses associées
 * POST /v1/app/tokens/{id}/addresses           associe une adresse
 * POST /v1/app/tokens/{id}/addresses/delete    retire une adresse
 */
final readonly class TokensController
{
    public function __construct(
        private TokenRepository $tokens,
        private MessageRepository $messages,
        private Session $session,
        private View $view,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->view->render('tokens', ['tokens' => $this->tokens->all()], title: 'Tokens');
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $name = trim((string) (((array) $request->getParsedBody())['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            return $this->back('/v1/app/tokens', 'error', 'Donnez un nom au jeton (255 caractères au maximum).');
        }

        $created = $this->tokens->create($name);
        // Le jeton en clair n'est jamais gardé : il est affiché une seule fois, sur la page suivante.
        $this->session->flash('new_token', $created);

        return $this->back(sprintf('/v1/app/tokens/%d', $created['id']), 'success', 'Jeton créé. Associez-lui maintenant des adresses.');
    }

    /** @param array{id: string} $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->token($request, $args);
        $this->tokens->delete($token['id']);

        return $this->back('/v1/app/tokens', 'success', sprintf('Jeton « %s » supprimé.', $token['name']));
    }

    /** @param array{id: string} $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->token($request, $args);
        $newToken = $this->session->takeFlash('new_token');
        $addresses = $this->tokens->addresses($token['id']);

        return $this->view->render('token', [
            'token'     => $token,
            'addresses' => $this->messages->countByAddress($addresses),
            'plain'     => is_array($newToken) && ($newToken['id'] ?? null) === $token['id'] ? (string) $newToken['token'] : null,
            'origin'    => sprintf('%s://%s', $request->getUri()->getScheme(), $request->getUri()->getAuthority()),
        ], title: $token['name']);
    }

    /** @param array{id: string} $args */
    public function addAddress(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->token($request, $args);
        $path = sprintf('/v1/app/tokens/%d', $token['id']);

        $address = Address::parse(((array) $request->getParsedBody())['address'] ?? null);
        if ($address === null) {
            return $this->back($path, 'error', 'Adresse e-mail invalide.');
        }

        return $this->tokens->addAddress($token['id'], $address)
            ? $this->back($path, 'success', sprintf('Adresse %s associée.', $address))
            : $this->back($path, 'error', sprintf('L\'adresse %s est déjà associée à ce jeton.', $address));
    }

    /** @param array{id: string} $args */
    public function removeAddress(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->token($request, $args);
        $address = Address::normalize((string) (((array) $request->getParsedBody())['address'] ?? ''));
        $this->tokens->removeAddress($token['id'], $address);

        return $this->back(sprintf('/v1/app/tokens/%d', $token['id']), 'success', sprintf('Adresse %s retirée.', $address));
    }

    /**
     * @param array{id: string} $args
     * @return array{id: int, name: string, hint: string, created_at: \DateTimeImmutable, last_used_at: ?\DateTimeImmutable}
     */
    private function token(ServerRequestInterface $request, array $args): array
    {
        $token = ctype_digit($args['id']) && strlen($args['id']) <= 10 ? $this->tokens->find((int) $args['id']) : null;
        if ($token === null) {
            throw new HttpNotFoundException($request, 'Jeton inconnu.');
        }

        return $token;
    }

    private function back(string $path, string $type, string $message): ResponseInterface
    {
        $this->session->flash('message', ['type' => $type, 'message' => $message]);

        return $this->view->redirect($path);
    }
}
