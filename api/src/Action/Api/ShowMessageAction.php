<?php

declare(strict_types=1);

namespace App\Action\Api;

use App\Auth\ApiScope;
use App\Http\Json;
use App\Mailpit\Message;
use App\Persistence\MessageRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/messages/{id} : un mail complet, s'il a été reçu par l'une des adresses du jeton.
 * Mêmes champs que dans la liste (GET /v1/messages), plus le corps (« text », « html ») et les
 * en-têtes (« headers »). Les pièces sont décrites, jamais leur fichier sur le disque.
 *
 * Avec le jeton du relais (depuis les IP autorisées), tous les mails sont visibles.
 *
 * Réponses :
 *   200 {"id": "…", "subject": "…", …, "parts": […], "text": "…", "html": "…", "headers": {…}}
 *   404 mail inconnu, ou reçu par aucune des adresses du jeton (même réponse : son existence
 *       n'est pas révélée)
 */
final readonly class ShowMessageAction
{
    public function __construct(
        private MessageRepository $repository,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    /** @param array{id: string} $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var ApiScope $scope */
        $scope = $request->getAttribute(ApiScope::ATTRIBUTE);
        $id = $args['id'];

        $message = preg_match(Message::ID_PATTERN, $id) === 1
            ? $this->repository->find($id, $scope->addresses, withPath: false)
            : null;
        if ($message === null) {
            return Json::response($this->responseFactory, 404, ['error' => 'mail inconnu']);
        }

        // Toujours un objet JSON, même vide (sinon « [] ») : en-tête => liste de valeurs.
        $message['headers'] = (object) $message['headers'];

        return Json::response($this->responseFactory, 200, $message);
    }
}
