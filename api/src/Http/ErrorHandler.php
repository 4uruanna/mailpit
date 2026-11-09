<?php

declare(strict_types=1);

namespace App\Http;

use App\Dashboard\ErrorPage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Slim\Interfaces\CallableResolverInterface;

/**
 * Gestionnaire d'erreurs Slim :
 *  - ne logue que les vraies erreurs : les 404 / 405 (scans, robots…) ne remplissent pas le log ;
 *  - URL du Dashboard (/v1/app/…) : page HTML d'erreur (ErrorPage) ; ailleurs, réponse JSON
 *    (forceContentType dans Kernel).
 */
final class ErrorHandler extends SlimErrorHandler
{
    public function __construct(
        CallableResolverInterface $callableResolver,
        ResponseFactoryInterface $responseFactory,
        private readonly ?ErrorPage $dashboard = null,
    ) {
        parent::__construct($callableResolver, $responseFactory);
    }

    protected function writeToErrorLog(): void
    {
        if ($this->exception instanceof HttpException) {
            return;
        }

        parent::writeToErrorLog();
    }

    protected function respond(): ResponseInterface
    {
        if ($this->dashboard === null || !$this->dashboard->handles($this->request)) {
            return parent::respond();
        }

        // Slim répond 200 à une requête OPTIONS (pré-vérification CORS de l'API) : le Dashboard n'en
        // fait pas, sa page d'erreur garde le vrai code (404, 405…), jamais « Erreur 200 ».
        $status = $this->exception instanceof HttpException ? $this->exception->getCode() : 500;
        $response = $this->dashboard->render($this->request, $this->exception, $status, $this->displayErrorDetails);
        if ($this->exception instanceof HttpMethodNotAllowedException) {
            $response = $response->withHeader('Allow', implode(', ', $this->exception->getAllowedMethods()));
        }

        return $response;
    }
}
