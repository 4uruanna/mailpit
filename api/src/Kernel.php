<?php

declare(strict_types=1);

namespace App;

use App\Action\MailpitWebhookAction;
use App\Http\ClientIpResolver;
use App\Http\ErrorHandler;
use App\Http\IpMatcher;
use App\Middleware\IpFilterMiddleware;
use App\Persistence\Database;
use App\Persistence\MessageRepository;
use App\Persistence\RawMessageStore;
use Slim\App;
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

        $app->post('/hook/mailpit', new MailpitWebhookAction(
            $repository,
            new RawMessageStore($settings->rawDir),
            $responseFactory,
            $settings->maxBodySize,
            $settings->token,
        ));

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
