<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\ErrorPage;
use App\Dashboard\SecurityHeaders;
use App\Dashboard\Session;
use App\Dashboard\View;
use App\Http\ClientIpResolver;
use App\Http\HttpsDetector;
use App\Http\IpMatcher;
use App\Tests\Support\TempDir;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;

/**
 * Page d'erreur du Dashboard, sans base ni session : URL concernées, titres et messages,
 * page minimale quand le gabarit ne peut pas être rendu.
 */
final class ErrorPageTest extends TestCase
{
    private static function page(string $basePath = '', ?string $templateDir = null): ErrorPage
    {
        $session = new Session($basePath . '/v1/app', 7200);
        $factory = new ResponseFactory();
        $view = new View($templateDir ?? dirname(__DIR__, 3) . '/templates', $basePath, new DateTimeZone('Europe/Paris'), $session, $factory);

        return new ErrorPage($view, $session, new HttpsDetector(new ClientIpResolver(new IpMatcher([]))), $factory, $basePath);
    }

    private static function request(string $path = '/v1/app/inconnue'): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', 'https://api.test' . $path, ['HTTPS' => 'on', 'SERVER_PORT' => '443']);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function paths(): iterable
    {
        yield 'racine du Dashboard'      => ['', '/v1/app', true];
        yield 'page'                     => ['', '/v1/app/messages', true];
        yield 'URL inconnue en dessous'  => ['', '/v1/app/inconnue/encore', true];
        yield 'chemin encodé'            => ['', '/v1/%61pp/messages', true];
        yield 'préfixe ressemblant'      => ['', '/v1/application', false];
        yield 'API'                      => ['', '/v1/messages', false];
        yield 'racine'                   => ['', '/', false];
        yield 'avec base_path'           => ['/api', '/api/v1/app/messages', true];
        yield 'sans le base_path'        => ['/api', '/v1/app/messages', false];
    }

    #[DataProvider('paths')]
    public function testHandlesOnlyDashboardUrls(string $basePath, string $path, bool $expected): void
    {
        self::assertSame($expected, self::page($basePath)->handles(self::request(path: $path)));
    }

    /** @return iterable<string, array{0: Throwable, 1: int, 2: string, 3: string}> */
    public static function errors(): iterable
    {
        $request = self::request();

        yield '404 de Slim'           => [new HttpNotFoundException($request), 404, 'Page introuvable', 'Cette page n\'existe pas : vérifiez l\'adresse.'];
        yield '404 avec message'      => [new HttpNotFoundException($request, 'Mail inconnu.'), 404, 'Page introuvable', 'Mail inconnu.'];
        yield '403 avec message'      => [new HttpForbiddenException($request, 'Interdit ici.'), 403, 'Accès refusé', 'Interdit ici.'];
        yield '405'                   => [new HttpMethodNotAllowedException($request)->setAllowedMethods(['GET', 'POST']), 405, 'Méthode non autorisée', 'Cette page n\'accepte pas la méthode DELETE (seulement GET, POST).'];
        yield '500 sans détail'       => [new RuntimeException('SQLSTATE[HY000] secret'), 500, 'Erreur interne', 'Une erreur est survenue sur le serveur. Elle a été écrite dans le log de l\'API (var/log/api.log).'];
        yield '500 de Slim, message'  => [new HttpInternalServerErrorException($request, 'détail interne'), 500, 'Erreur interne', 'Une erreur est survenue sur le serveur. Elle a été écrite dans le log de l\'API (var/log/api.log).'];
    }

    #[DataProvider('errors')]
    public function testTitleAndMessage(Throwable $exception, int $status, string $title, string $message): void
    {
        self::assertSame([$title, $message], ErrorPage::describe($exception, $status, 'DELETE'));
    }

    public function testRendersTheDashboardLayoutWithoutSession(): void
    {
        $response = self::page()->render(self::request(), new RuntimeException('détail-confidentiel'), 500, false);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(SecurityHeaders::HEADERS['Content-Security-Policy'], $response->getHeaderLine('Content-Security-Policy'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('<title>Erreur interne · Amnesic Mail</title>', $body);
        self::assertStringContainsString('<script src="/v1/app/dashboard.js"></script>', $body);
        self::assertStringNotContainsString('détail-confidentiel', $body);
        self::assertStringNotContainsString('Déconnexion', $body);
        self::assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testMinimalPageWhenTheTemplateCannotBeRendered(): void
    {
        $log = TempDir::logFile();
        clearstatcache();
        $offset = is_file($log) ? (int) filesize($log) : 0;

        $page = self::page('/api', '/dossier/inexistant');
        $response = $page->render(self::request(path: '/api/v1/app/messages'), new RuntimeException('base injoignable'), 500, false);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        foreach (SecurityHeaders::HEADERS as $name => $value) {
            self::assertSame($value, $response->getHeaderLine($name), $name);
        }
        $body = (string) $response->getBody();
        self::assertStringContainsString('<h1>Erreur interne</h1>', $body);
        self::assertStringContainsString('<a href="/api/v1/app/messages">Retour aux mails</a>', $body);
        self::assertStringNotContainsString('base injoignable', $body);

        // Avec debug, le détail est échappé.
        $response = $page->render(self::request(path: '/api/v1/app/messages'), new RuntimeException('<b>base</b>'), 500, true);
        self::assertStringContainsString('RuntimeException : &lt;b&gt;base&lt;/b&gt;', (string) $response->getBody());

        clearstatcache();
        self::assertStringContainsString(
            '[dashboard] page d\'erreur 500 impossible à afficher, page minimale envoyée : RuntimeException : gabarit introuvable : error',
            (string) file_get_contents($log, offset: $offset),
        );
    }
}
