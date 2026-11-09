<?php

declare(strict_types=1);

namespace App;

use App\Action\Api\DeleteAddressMessagesAction;
use App\Action\Api\DeleteMessageAction;
use App\Action\Api\ListAddressesAction;
use App\Action\Api\ListMessagesAction;
use App\Action\CreateMessageAction;
use App\Action\Dashboard\MessagesController;
use App\Action\Dashboard\SignInController;
use App\Action\Dashboard\TokensController;
use App\Action\UploadPartAction;
use App\Auth\LoginThrottle;
use App\Dashboard\Session;
use App\Dashboard\View;
use App\Http\ClientIpResolver;
use App\Http\ErrorHandler;
use App\Http\IpMatcher;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\DashboardMiddleware;
use App\Middleware\IpFilterMiddleware;
use App\Middleware\RequireAdminMiddleware;
use App\Middleware\TokenAuthMiddleware;
use App\Persistence\AdminRepository;
use App\Persistence\Database;
use App\Persistence\FileStore;
use App\Persistence\LoginAttemptRepository;
use App\Persistence\MessageRepository;
use App\Persistence\TokenRepository;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

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

        $database = new Database($settings->db);
        $messages = new MessageRepository($database);
        $tokens = new TokenRepository($database);
        $admins = new AdminRepository($database);
        $files = new FileStore($settings->storageDir);

        $ipResolver = new ClientIpResolver(new IpMatcher($settings->trustedProxies), $settings->proxyHeader);
        $relayIps = new IpMatcher($settings->allowedIps);

        // ── Relais : seul autorisé à envoyer des mails ───────────────────────────────────────
        // Filtre IP (403) puis jeton du relais (401), avant toute lecture du corps.
        $ipFilter = new IpFilterMiddleware($ipResolver, $relayIps, $responseFactory);
        $relayAuth = new TokenAuthMiddleware($settings->token, $responseFactory);

        $create = new CreateMessageAction(
            $messages,
            $files,
            $responseFactory,
            $settings->maxBodySize,
            $settings->maxPartSize,
            $settings->maxMessages,
        );
        $upload = new UploadPartAction($messages, $files, $responseFactory, $settings->maxPartSize);

        // ── Développeurs : jetons créés dans le Dashboard ────────────────────────────────────
        $tokenAuth = new ApiTokenMiddleware($tokens, $responseFactory);
        // GET /v1/messages accepte aussi le jeton du relais (tous les mails, depuis les IP autorisées).
        $listAuth = new ApiTokenMiddleware($tokens, $responseFactory, $settings->token, $ipResolver, $relayIps);

        $list = new ListMessagesAction($messages, $responseFactory);
        $deleteMessage = new DeleteMessageAction($messages, $files, $responseFactory);
        $listAddresses = new ListAddressesAction($messages, $responseFactory);
        $deleteAddress = new DeleteAddressMessagesAction($messages, $files, $responseFactory);

        // ── Dashboard des administrateurs ────────────────────────────────────────────────────
        $session = new Session($settings->basePath . '/v1/app', $settings->sessionLifetime);
        $view = new View(dirname(__DIR__) . '/templates', $settings->basePath, new DateTimeZone($settings->timezone), $session, $responseFactory);
        $signIn = new SignInController(new LoginThrottle($admins, new LoginAttemptRepository($database)), $session, $view, $ipResolver);
        $messagesPage = new MessagesController($messages, $view);
        $tokensPage = new TokensController($tokens, $messages, $session, $view);

        //   POST   /v1/messages                     relais : le mail (JSON)
        //   POST   /v1/messages/{id}/part/{partId}  relais : le contenu d'une pièce jointe
        //   GET    /v1/messages                     jeton : mails de ses adresses (?address, limit, offset, page)
        //   DELETE /v1/messages/{id}                jeton : supprime un mail de ses adresses
        //   GET    /v1/addresses                    jeton : ses adresses
        //   DELETE /v1/addresses?address=…          jeton : supprime les mails d'une de ses adresses
        //   /v1/app/…                               Dashboard (session)
        $app->group('/v1', function (RouteCollectorProxy $v1) use (
            $create, $upload, $ipFilter, $relayAuth,
            $list, $deleteMessage, $listAddresses, $deleteAddress, $tokenAuth, $listAuth,
            $session, $view, $admins, $signIn, $messagesPage, $tokensPage,
        ): void {
            // Middlewares de route : le dernier ajouté s'exécute en premier (filtre IP d'abord).
            $v1->post('/messages', $create)->add($relayAuth)->add($ipFilter);
            $v1->post('/messages/{id}/part/{partId}', $upload)->add($relayAuth)->add($ipFilter);

            $v1->get('/messages', $list)->add($listAuth);
            $v1->delete('/messages/{id}', $deleteMessage)->add($tokenAuth);
            $v1->get('/addresses', $listAddresses)->add($tokenAuth);
            $v1->delete('/addresses', $deleteAddress)->add($tokenAuth);

            $v1->group('/app', function (RouteCollectorProxy $dashboard) use ($session, $view, $admins, $signIn, $messagesPage, $tokensPage): void {
                $dashboard->get('/signin', [$signIn, 'show']);
                $dashboard->post('/signin', [$signIn, 'submit']);

                $dashboard->group('', function (RouteCollectorProxy $admin) use ($view, $signIn, $messagesPage, $tokensPage): void {
                    $admin->get('', static fn (): ResponseInterface => $view->redirect('/v1/app/messages'));
                    $admin->post('/signout', [$signIn, 'signOut']);
                    $admin->get('/messages', [$messagesPage, 'index']);
                    $admin->get('/tokens', [$tokensPage, 'index']);
                    $admin->post('/tokens', [$tokensPage, 'create']);
                    $admin->get('/tokens/{id:[0-9]+}', [$tokensPage, 'show']);
                    $admin->post('/tokens/{id:[0-9]+}/delete', [$tokensPage, 'delete']);
                    $admin->post('/tokens/{id:[0-9]+}/addresses', [$tokensPage, 'addAddress']);
                    $admin->post('/tokens/{id:[0-9]+}/addresses/delete', [$tokensPage, 'removeAddress']);
                })->add(new RequireAdminMiddleware($session, $admins, $view));
            })->add(new DashboardMiddleware($session, $view));
        });

        // Ajouté en dernier = exécuté en premier : attrape toutes les erreurs.
        $errorHandler = new ErrorHandler($app->getCallableResolver(), $responseFactory);
        $errorHandler->forceContentType('application/json');
        $errorMiddleware = $app->addErrorMiddleware($settings->debug, true, true);
        $errorMiddleware->setDefaultErrorHandler($errorHandler);

        return $app;
    }
}
