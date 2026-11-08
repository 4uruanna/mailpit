<?php

declare(strict_types=1);

namespace App;

use App\Action\MailpitWebhookAction;
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

        $hook = new MailpitWebhookAction(
            $repository,
            $responseFactory,
            $settings->maxBodySize,
            $settings->maxPartSize,
            $settings->maxRawSize,
        );
        $upload = new UploadPartAction(
            $repository,
            new FileStore($settings->storageDir),
            $responseFactory,
            $settings->maxPartSize,
            $settings->maxRawSize,
        );

        // Toutes les routes du webhook exigent le jeton (vérifié avant la lecture du corps).
        $app->group('/hook/mailpit', function (RouteCollectorProxy $group) use ($hook, $upload): void {
            $group->post('', $hook);
            $group->put('/{id}/parts/{partId}', $upload);
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
