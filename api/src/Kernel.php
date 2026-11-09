<?php

declare(strict_types=1);

namespace App;

use App\Action\CreateMessageAction;
use App\Action\ListMessagesAction;
use App\Action\UploadPartAction;
use App\Http\ClientIpResolver;
use App\Http\ErrorHandler;
use App\Http\IpMatcher;
use App\Middleware\IpFilterMiddleware;
use App\Middleware\TokenAuthMiddleware;
use App\Persistence\Database;
use App\Persistence\FileStore;
use App\Persistence\MessageRepository;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Slim\Factory\AppFactory;

/**
 * Construit l'application Slim à partir de la configuration.
 */
final class Kernel
{
    public static function create(array $config): App
    {
        $settings = Settings::fromArray($config);

        ini_set('display_errors', $settings->debug ? '1' : '0');
        ini_set('log_errors', '1');
        if ($settings->logFile !== null) {
            ini_set('error_log', $settings->logFile);
        }

        $app = AppFactory::create();
        $app->setBasePath($settings->basePath);
        $responseFactory = $app->getResponseFactory();

        $repository = new MessageRepository(new Database($settings->db));

        $files = new FileStore($settings->storageDir);
        $create = new CreateMessageAction(
            $repository,
            $files,
            $responseFactory,
            $settings->maxBodySize,
            $settings->maxPartSize,
            $settings->maxMessages,
        );
        $upload = new UploadPartAction($repository, $files, $responseFactory, $settings->maxPartSize);
        $list = new ListMessagesAction($repository, $responseFactory);

        // Toutes les routes exigent le jeton (vérifié avant la lecture du corps).
        //   POST /v1/messages                     le mail (JSON)
        //   POST /v1/messages/{id}/part/{partId}  le contenu d'une pièce jointe
        //   GET  /v1/messages                     la liste des mails enregistrés
        $app->group('/v1/messages', function (RouteCollectorProxy $group) use ($create, $upload, $list): void {
            $group->post('', $create);
            $group->get('', $list);
            $group->post('/{id}/part/{partId}', $upload);
        })->add(new TokenAuthMiddleware($settings->token, $responseFactory));

        // Filtre IP global : exécuté avant le routage, toute requête d'une IP
        // non autorisée reçoit un 403 (même sur une URL inexistante).
        $app->add(new IpFilterMiddleware(
            new ClientIpResolver(new IpMatcher($settings->trustedProxies), $settings->proxyHeader),
            new IpMatcher($settings->allowedIps),
            $responseFactory,
        ));

        // Ajouté en dernier = exécuté en premier : attrape toutes les erreurs.
        $errorHandler = new ErrorHandler($app->getCallableResolver(), $responseFactory);
        $errorHandler->forceContentType('application/json');
        $errorMiddleware = $app->addErrorMiddleware($settings->debug, true, true);
        $errorMiddleware->setDefaultErrorHandler($errorHandler);

        return $app;
    }
}
