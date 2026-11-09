<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Http\HttpsDetector;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Throwable;

/**
 * Page d'erreur HTML des URL du Dashboard (base_path + /v1/app et en dessous), avec sa mise en page,
 * au lieu de la réponse JSON de l'API : titre et message en français selon le code, lien de retour
 * vers les mails, et les mêmes en-têtes de sécurité que les autres pages (SecurityHeaders).
 *
 *  - 404 : « Page introuvable », ou le message de l'exception (« Mail inconnu. ») ;
 *  - 405 : « Méthode non autorisée » ;
 *  - 500 : « Erreur interne », sans détail sauf si 'debug' => true.
 *
 * Le menu de l'administrateur n'apparaît que si sa session est ouverte. Si la page ne peut pas être
 * rendue (gabarit manquant, erreur dans le gabarit…), une page HTML minimale la remplace : une
 * erreur ne doit jamais en provoquer une seconde.
 */
final readonly class ErrorPage
{
    public function __construct(
        private View $view,
        private Session $session,
        private HttpsDetector $https,
        private ResponseFactoryInterface $responseFactory,
        private string $basePath,
    ) {
    }

    /**
     * URL du Dashboard : base_path + /v1/app, et tout ce qui est en dessous (même une URL inconnue).
     * Chemin décodé, comme pour le routage de Slim : /v1/%61pp/… est aussi le Dashboard.
     */
    public function handles(ServerRequestInterface $request): bool
    {
        $path = rawurldecode($request->getUri()->getPath());
        $prefix = $this->basePath . '/v1/app';

        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    public function render(ServerRequestInterface $request, Throwable $exception, int $status, bool $displayDetails): ResponseInterface
    {
        [$title, $message] = self::describe($exception, $status, $request->getMethod());
        $details = $displayDetails ? self::details($exception) : null;

        $this->startSession($request);

        try {
            $response = $this->view->render('error', [
                'status'  => $status,
                'heading' => $title,
                'message' => $message,
                'details' => $details,
                // Un message flash en attente reste pour la page suivante.
                'flash'   => null,
            ], $status, $title);
        } catch (Throwable $failure) {
            error_log(sprintf('[dashboard] page d\'erreur %d impossible à afficher, page minimale envoyée : %s', $status, self::summary($failure)));
            $response = $this->minimal($status, $title, $message, $details);
        }

        return SecurityHeaders::apply($response);
    }

    /**
     * Titre et message, en français, selon le code.
     *
     * @return array{0: string, 1: string}
     */
    public static function describe(Throwable $exception, int $status, string $method): array
    {
        $title = match (true) {
            $status === 400 => 'Requête invalide',
            $status === 403 => 'Accès refusé',
            $status === 404 => 'Page introuvable',
            $status === 405 => 'Méthode non autorisée',
            $status >= 500  => 'Erreur interne',
            default         => 'Requête refusée',
        };

        $message = self::customMessage($exception) ?? match (true) {
            $status === 404 => 'Cette page n\'existe pas : vérifiez l\'adresse.',
            $status === 405 => sprintf(
                'Cette page n\'accepte pas la méthode %s%s.',
                $method,
                $exception instanceof HttpMethodNotAllowedException && $exception->getAllowedMethods() !== []
                    ? sprintf(' (seulement %s)', implode(', ', $exception->getAllowedMethods()))
                    : '',
            ),
            $status >= 500  => 'Une erreur est survenue sur le serveur. Elle a été écrite dans le log de l\'API (var/log/api.log).',
            default         => 'Cette requête ne peut pas être traitée.',
        };

        return [$title, $message];
    }

    /**
     * Message choisi par le code (« Mail inconnu. ») d'une erreur 4xx de Slim, plutôt que son message
     * anglais par défaut (« Not found. »). Jamais pour une erreur 500 : il pourrait révéler un détail.
     * Ni pour une 405 : Slim y écrit lui-même la liste des méthodes, en anglais.
     */
    private static function customMessage(Throwable $exception): ?string
    {
        if (!$exception instanceof HttpException || $exception instanceof HttpMethodNotAllowedException || $exception->getCode() >= 500) {
            return null;
        }

        $default = new ReflectionClass($exception)->getDefaultProperties()['message'] ?? '';
        $message = $exception->getMessage();

        return $message !== '' && $message !== $default ? $message : null;
    }

    /** Détail affiché seulement avec 'debug' => true : classe, message, fichier, pile, exceptions précédentes. */
    private static function details(Throwable $exception): string
    {
        $blocks = [];
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $blocks[] = self::summary($current) . "\n" . $current->getTraceAsString();
        }

        return implode("\n\nCausée par : ", $blocks);
    }

    private static function summary(Throwable $exception): string
    {
        return sprintf('%s : %s (%s:%d)', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine());
    }

    /**
     * Ouvre la session si le navigateur en envoie le cookie, pour afficher le menu de l'administrateur :
     * une URL inconnue n'est passée par aucun middleware du Dashboard. Sans cookie (robots, scans),
     * aucune session n'est créée. Un échec n'empêche pas la page : elle s'affiche sans menu.
     */
    private function startSession(ServerRequestInterface $request): void
    {
        if ($this->session->isActive() || !Session::hasCookie($request->getCookieParams())) {
            return;
        }

        try {
            $this->session->start($this->https->isHttps($request));
        } catch (Throwable $failure) {
            error_log(sprintf('[dashboard] session impossible à ouvrir pour la page d\'erreur : %s', self::summary($failure)));
        }
    }

    /** Page de secours, sans gabarit ni session. */
    private function minimal(int $status, string $title, string $message, ?string $details): ResponseInterface
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = sprintf(
            "<!doctype html>\n<html lang=\"fr\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"robots\" content=\"noindex, nofollow\">\n<title>%s · Amnesic Mail</title>\n</head>\n<body>\n<h1>%s</h1>\n<p>%s</p>\n%s<p><a href=\"%s\">Retour aux mails</a></p>\n</body>\n</html>\n",
            $e($title),
            $e($title),
            $e($message),
            $details !== null ? '<pre>' . $e($details) . "</pre>\n" : '',
            $e($this->basePath . '/v1/app/messages'),
        );

        $response = $this->responseFactory->createResponse($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($html);

        return $response;
    }
}
