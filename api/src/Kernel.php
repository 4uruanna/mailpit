<?php

declare(strict_types=1);

namespace App;

use App\Action\Api\DeleteAddressMessagesAction;
use App\Action\Api\DeleteMessageAction;
use App\Action\Api\ListAddressesAction;
use App\Action\Api\ListMessagesAction;
use App\Action\Api\ShowMessageAction;
use App\Action\CreateMessageAction;
use App\Action\Dashboard\MessagesController;
use App\Action\Dashboard\SignInController;
use App\Action\Dashboard\TokensController;
use App\Action\UploadPartAction;
use App\Auth\LoginThrottle;
use App\Dashboard\ErrorPage;
use App\Dashboard\Session;
use App\Dashboard\View;
use App\Http\ClientIpResolver;
use App\Http\ErrorHandler;
use App\Http\HttpsDetector;
use App\Http\IpMatcher;
use App\Log\LogRotator;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\DashboardMiddleware;
use App\Middleware\ForceHttpsMiddleware;
use App\Middleware\IpFilterMiddleware;
use App\Middleware\MaintenanceMiddleware;
use App\Middleware\RequireAdminMiddleware;
use App\Middleware\TokenAuthMiddleware;
use App\Persistence\AdminRepository;
use App\Persistence\Database;
use App\Persistence\FileStore;
use App\Persistence\LoginAttemptRepository;
use App\Persistence\Maintenance;
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
        // Rotation du log (log_max_size, 10 Mo par défaut) avant toute écriture, puis error_log()
        // vers log_file. Un simple stat tant que le fichier est sous la limite, et une rotation
        // impossible n'empêche jamais la requête.
        new LogRotator($settings->logFile, $settings->logMaxSize)->start();

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
        $https = new HttpsDetector($ipResolver);

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
        // Nettoyage (mails trop anciens, au-delà de max_messages, fichiers orphelins) après les
        // requêtes acceptées du relais, au plus toutes les 15 minutes : aucune tâche planifiée.
        $maintenance = new MaintenanceMiddleware(new Maintenance($messages, $files, $settings->maxAgeDays, $settings->maxMessages));

        // ── Développeurs : jetons créés dans le Dashboard ────────────────────────────────────
        $tokenAuth = new ApiTokenMiddleware($tokens, $responseFactory);
        // La lecture des mails (GET /v1/messages et /v1/messages/{id}) accepte aussi le jeton du relais
        // (tous les mails, depuis les IP autorisées).
        $listAuth = new ApiTokenMiddleware($tokens, $responseFactory, $settings->token, $ipResolver, $relayIps);

        $list = new ListMessagesAction($messages, $responseFactory);
        $show = new ShowMessageAction($messages, $responseFactory);
        $deleteMessage = new DeleteMessageAction($messages, $files, $responseFactory);
        $listAddresses = new ListAddressesAction($messages, $responseFactory);
        $deleteAddress = new DeleteAddressMessagesAction($messages, $files, $responseFactory);

        // ── Dashboard des administrateurs ────────────────────────────────────────────────────
        $session = new Session($settings->basePath . '/v1/app', $settings->sessionLifetime);
        $view = new View(dirname(__DIR__) . '/templates', $settings->basePath, new DateTimeZone($settings->timezone), $session, $responseFactory);
        $signIn = new SignInController(new LoginThrottle($admins, new LoginAttemptRepository($database)), $session, $view, $ipResolver);
        $messagesPage = new MessagesController($messages, $files, $session, $view);
        $tokensPage = new TokensController($tokens, $messages, $session, $view, $https);

        //   POST   /v1/messages                     relais : le mail (JSON), puis nettoyage si c'est le moment
        //   POST   /v1/messages/{id}/part/{partId}  relais : le contenu d'une pièce jointe, puis nettoyage
        //   GET    /v1/messages                     jeton : résumés des mails de ses adresses (?address, limit, offset, page)
        //   GET    /v1/messages/{id}                jeton : un mail complet (corps, en-têtes) de ses adresses
        //   DELETE /v1/messages/{id}                jeton : supprime un mail de ses adresses
        //   GET    /v1/addresses                    jeton : ses adresses
        //   DELETE /v1/addresses?address=…          jeton : supprime les mails d'une de ses adresses
        //   /v1/app/…                               Dashboard (session) ; erreurs en page HTML
        $app->group('/v1', function (RouteCollectorProxy $v1) use (
            $create, $upload, $ipFilter, $relayAuth, $maintenance,
            $list, $show, $deleteMessage, $listAddresses, $deleteAddress, $tokenAuth, $listAuth,
            $session, $view, $admins, $signIn, $messagesPage, $tokensPage, $https,
        ): void {
            // Middlewares de route : le dernier ajouté s'exécute en premier (filtre IP, jeton, puis
            // la route ; le nettoyage a lieu une fois la réponse calculée).
            $v1->post('/messages', $create)->add($maintenance)->add($relayAuth)->add($ipFilter);
            $v1->post('/messages/{id}/part/{partId}', $upload)->add($maintenance)->add($relayAuth)->add($ipFilter);

            $v1->get('/messages', $list)->add($listAuth);
            // Sans contrainte sur {id}, comme DELETE : l'action vérifie l'ID (404 « mail inconnu »).
            // Une contrainte ferait répondre 405 au lieu de 404 pour un ID invalide (DELETE correspond).
            $v1->get('/messages/{id}', $show)->add($listAuth);
            $v1->delete('/messages/{id}', $deleteMessage)->add($tokenAuth);
            $v1->get('/addresses', $listAddresses)->add($tokenAuth);
            $v1->delete('/addresses', $deleteAddress)->add($tokenAuth);

            $v1->group('/app', function (RouteCollectorProxy $dashboard) use ($session, $view, $admins, $signIn, $messagesPage, $tokensPage, $https): void {
                $dashboard->get('/signin', [$signIn, 'show']);
                $dashboard->post('/signin', [$signIn, 'submit']);
                // Script des pages (confirmations, sélection) : seul JavaScript autorisé par la CSP.
                $dashboard->get('/dashboard.js', static fn (): ResponseInterface => $view->script('dashboard.js'));

                $dashboard->group('', function (RouteCollectorProxy $admin) use ($view, $signIn, $messagesPage, $tokensPage): void {
                    $admin->get('', static fn (): ResponseInterface => $view->redirect('/v1/app/messages'));
                    $admin->post('/signout', [$signIn, 'signOut']);
                    $admin->get('/messages', [$messagesPage, 'index']);
                    $admin->get('/messages/{id:[A-Za-z0-9_-]+}', [$messagesPage, 'show']);
                    $admin->get('/messages/{id:[A-Za-z0-9_-]+}/html', [$messagesPage, 'html']);
                    $admin->get('/messages/{id:[A-Za-z0-9_-]+}/parts/{partId:[0-9.]+}', [$messagesPage, 'download']);
                    $admin->post('/messages/delete', [$messagesPage, 'deleteSelected']);
                    $admin->post('/messages/{id:[A-Za-z0-9_-]+}/delete', [$messagesPage, 'deleteOne']);
                    $admin->get('/tokens', [$tokensPage, 'index']);
                    $admin->post('/tokens', [$tokensPage, 'create']);
                    $admin->get('/tokens/{id:[0-9]+}', [$tokensPage, 'show']);
                    $admin->post('/tokens/{id:[0-9]+}/delete', [$tokensPage, 'delete']);
                    $admin->post('/tokens/{id:[0-9]+}/addresses', [$tokensPage, 'addAddress']);
                    $admin->post('/tokens/{id:[0-9]+}/addresses/delete', [$tokensPage, 'removeAddress']);
                })->add(new RequireAdminMiddleware($session, $admins, $view));
            })->add(new DashboardMiddleware($session, $view, $https));
        });

        // Attrape toutes les erreurs des routes et des middlewares ajoutés avant lui. Réponses JSON,
        // sauf sous /v1/app : page HTML d'erreur du Dashboard, avec ses en-têtes de sécurité.
        $errorPage = new ErrorPage($view, $session, $https, $responseFactory, $settings->basePath);
        $errorHandler = new ErrorHandler($app->getCallableResolver(), $responseFactory, $errorPage);
        $errorHandler->forceContentType('application/json');
        $errorMiddleware = $app->addErrorMiddleware($settings->debug, true, true);
        $errorMiddleware->setDefaultErrorHandler($errorHandler);

        // HTTPS obligatoire. Ajouté après la gestion des erreurs, donc exécuté avant elle et avant le
        // routage : une URL inconnue est aussi redirigée, et les pages d'erreur reçoivent l'en-tête HSTS.
        if ($settings->forceHttps) {
            $app->add(new ForceHttpsMiddleware($https, $ipResolver, $responseFactory));
        }

        return $app;
    }
}
