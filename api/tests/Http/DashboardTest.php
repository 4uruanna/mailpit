<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Dashboard\SecurityHeaders;
use App\Dashboard\Session;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Dashboard (/v1/app/…) : session PHP (session_start), un processus par test pour que chaque
 * test parte d'une session vide et que les en-têtes de session puissent être envoyés.
 */
#[RunTestsInSeparateProcesses]
final class DashboardTest extends AppTestCase
{
    private const string PASSWORD = 'mot-de-passe-solide';

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('session.save_path', $this->storageDir);
    }

    private function createAdmin(): void
    {
        $this->pdo->prepare('INSERT INTO mailpit_admins (username, password) VALUES (?, ?)')
            ->execute(['admin', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    /** Jeton anti-CSRF du premier formulaire de la page. */
    private static function csrf(ResponseInterface $response): string
    {
        self::assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]{64})"/', (string) $response->getBody(), $match));

        return $match[1];
    }

    private function signIn(): void
    {
        $this->createAdmin();
        $csrf = self::csrf($this->request('GET', '/v1/app/signin', ip: self::OTHER_IP));

        $response = $this->request('POST', '/v1/app/signin', ip: self::OTHER_IP, form: [
            '_csrf'    => $csrf,
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/v1/app/messages', $response->getHeaderLine('Location'));
    }

    public function testPagesRedirectToSignInWhenNotSignedIn(): void
    {
        foreach (['/v1/app', '/v1/app/messages', '/v1/app/tokens', '/v1/app/status', '/v1/app/messages/m1'] as $path) {
            $response = $this->request('GET', $path, ip: self::OTHER_IP);

            self::assertSame(303, $response->getStatusCode(), $path);
            self::assertSame('/v1/app/signin', $response->getHeaderLine('Location'), $path);
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), $path);
            self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), $path);
        }
    }

    public function testSignInPage(): void
    {
        $response = $this->request('GET', '/v1/app/signin', ip: self::OTHER_IP);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('name="password"', (string) $response->getBody());
        self::csrf($response);
    }

    public function testFormWithoutCsrfIsRefused(): void
    {
        $this->createAdmin();

        $response = $this->request('POST', '/v1/app/signin', ip: self::OTHER_IP, form: ['username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/v1/app/signin', $response->getHeaderLine('Location'));

        $response = $this->request('POST', '/v1/app/signin', ip: self::OTHER_IP, form: ['_csrf' => str_repeat('0', 64), 'username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(303, $response->getStatusCode());

        // Le formulaire n'a pas été traité : ni tentative comptée, ni connexion.
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_login_attempts'));
        self::assertStringContainsString('Formulaire expiré', (string) $this->request('GET', '/v1/app/signin', ip: self::OTHER_IP)->getBody());
        self::assertSame(303, $this->request('GET', '/v1/app/messages', ip: self::OTHER_IP)->getStatusCode());
    }

    public function testWrongPassword(): void
    {
        $this->createAdmin();
        $csrf = self::csrf($this->request('GET', '/v1/app/signin', ip: self::OTHER_IP));

        $response = $this->request('POST', '/v1/app/signin', ip: self::OTHER_IP, form: ['_csrf' => $csrf, 'username' => 'admin', 'password' => 'faux']);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Encore 2 essai(s)', (string) $response->getBody());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_login_attempts'));
    }

    public function testBlockedSignInMessages(): void
    {
        $this->createAdmin();
        $csrf = self::csrf($this->request('GET', '/v1/app/signin', ip: self::OTHER_IP));
        $insert = $this->pdo->prepare('INSERT INTO mailpit_login_attempts (username, ip, ip_key) VALUES (?, ?, ?)');

        // 3 échecs pour admin depuis cette adresse : identifiant bloqué pour elle seule.
        for ($i = 0; $i < 3; $i++) {
            $insert->execute(['admin', self::OTHER_IP, self::OTHER_IP]);
        }
        $response = $this->request('POST', '/v1/app/signin', ip: self::OTHER_IP, form: ['_csrf' => $csrf, 'username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('Trop de tentatives pour cet identifiant depuis votre adresse.', (string) $response->getBody());
        self::assertGreaterThan(1700, (int) $response->getHeaderLine('Retry-After'));

        // 10 échecs depuis une autre adresse, sur des identifiants différents : adresse bloquée.
        for ($i = 0; $i < 10; $i++) {
            $insert->execute(['inconnu' . $i, '192.0.2.50', '192.0.2.50']);
        }
        $response = $this->request('POST', '/v1/app/signin', ip: '192.0.2.50', form: ['_csrf' => $csrf, 'username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('Trop de tentatives depuis votre adresse.', (string) $response->getBody());
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));

        // Depuis une troisième adresse, l'administrateur se connecte.
        $response = $this->request('POST', '/v1/app/signin', ip: '192.0.2.51', form: ['_csrf' => $csrf, 'username' => 'admin', 'password' => self::PASSWORD]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/v1/app/messages', $response->getHeaderLine('Location'));
    }

    public function testSignedInAdminSeesMessagesAndNeedsCsrf(): void
    {
        $this->postMessage(self::payload('m1'));
        $this->signIn();

        $response = $this->request('GET', '/v1/app/messages', ip: self::OTHER_IP);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Sujet m1', (string) $response->getBody());
        // Cases à cocher de la suppression : jamais recochées par le navigateur au retour sur la liste.
        self::assertMatchesRegularExpression('#<form [^>]*data-selection autocomplete="off"#', (string) $response->getBody());
        $csrf = self::csrf($response);

        // Connecté, mais formulaire sans jeton anti-CSRF : refusé, retour à la liste.
        $response = $this->request('POST', '/v1/app/tokens', ip: self::OTHER_IP, form: ['name' => 'Projet A']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/v1/app/messages', $response->getHeaderLine('Location'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_tokens'));

        $response = $this->request('POST', '/v1/app/tokens', ip: self::OTHER_IP, form: ['_csrf' => $csrf, 'name' => 'Projet A']);
        self::assertSame(303, $response->getStatusCode());
        self::assertMatchesRegularExpression('#^/v1/app/tokens/\d+$#', $response->getHeaderLine('Location'));
        self::assertSame('Projet A', $this->scalar('SELECT name FROM mailpit_tokens'));
    }

    public function testTruncatedMessageHasABadge(): void
    {
        $this->postMessage(['Truncated' => true] + self::payload('m1'));
        $this->postMessage(self::payload('m2'));
        $this->signIn();

        // Liste : badge sur le mail tronqué seulement.
        $body = (string) $this->request('GET', '/v1/app/messages', ip: self::OTHER_IP)->getBody();
        self::assertSame(1, substr_count($body, '>tronqué</span>'));
        self::assertMatchesRegularExpression('#Sujet m1</a>(?:(?!</tr>).)*<span class="tag warn"[^>]*>tronqué</span>#s', $body);

        // Page du mail : badge et explication.
        $body = (string) $this->request('GET', '/v1/app/messages/m1', ip: self::OTHER_IP)->getBody();
        self::assertStringContainsString('<span class="tag warn">tronqué</span>', $body);
        self::assertStringContainsString('Ce mail dépassait la taille maximale acceptée par l\'API', $body);
        $body = (string) $this->request('GET', '/v1/app/messages/m2', ip: self::OTHER_IP)->getBody();
        self::assertStringNotContainsString('tronqué', $body);
    }

    public function testSessionCookieIsSecureInHttps(): void
    {
        self::assertSame(200, $this->request('GET', '/v1/app/signin', ip: self::OTHER_IP)->getStatusCode());

        self::assertTrue(session_get_cookie_params()['secure']);
    }

    public function testSessionCookieIsSecureBehindATrustedProxy(): void
    {
        // Le proxy parle en http:// à l'hébergement, le client est en HTTPS : même détection que force_https.
        $config = $this->config();
        $config['proxy'] = ['trusted_proxies' => ['10.0.0.1']];
        $this->useConfig($config);

        $response = $this->request('GET', '/v1/app/signin', ['X-Forwarded-Proto' => 'https'], ip: '10.0.0.1', https: false);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(session_get_cookie_params()['secure']);
    }

    public function testSessionCookieIsNotSecureInClearWhenHttpsIsNotForced(): void
    {
        // Développement local (force_https = false) : le cookie doit rester utilisable en http://.
        $this->useConfig(['force_https' => false] + $this->config());

        self::assertSame(200, $this->request('GET', '/v1/app/signin', ip: self::OTHER_IP, https: false)->getStatusCode());
        self::assertFalse(session_get_cookie_params()['secure']);
    }

    public function testTokenExampleIsInHttpsBehindTheOvhFrontEnd(): void
    {
        $this->signIn();
        $csrf = self::csrf($this->request('GET', '/v1/app/tokens', ip: self::OTHER_IP));
        $location = $this->request('POST', '/v1/app/tokens', ip: self::OTHER_IP, form: ['_csrf' => $csrf, 'name' => 'Projet A'])
            ->getHeaderLine('Location');

        // Frontal OVH : port 443 sans HTTPS=on, l'URL vue par PHP est donc « http://api.test:443 ».
        $request = new ServerRequestFactory()->createServerRequest('GET', 'http://api.test:443' . $location, [
            'REMOTE_ADDR' => self::OTHER_IP,
            'SERVER_PORT' => '443',
        ]);
        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('https://api.test/v1/messages?address=', $body);
        self::assertStringContainsString('https://api.test/v1/messages/ID_DU_MAIL', $body);
        self::assertStringContainsString('&amp;since=$DEPUIS&amp;wait=60"', $body);
        self::assertStringNotContainsString('http://api.test', $body);
    }

    public function testStatusPage(): void
    {
        $this->signIn();

        // Aucun ping : la page l'explique.
        $response = $this->request('GET', '/v1/app/status', ip: self::OTHER_IP);
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<span class="badge none">Aucun ping reçu</span>', $body);
        self::assertStringContainsString('Aucune coupure sur les dernières 24 heures.', $body);

        // Le relais envoie des pings toutes les 30 secondes depuis 10 minutes, après 5 minutes de coupure.
        $insert = $this->pdo->prepare("INSERT INTO mailpit_health_pings (received_at, latency_ms, pending, failed, source_ip, label) VALUES (UTC_TIMESTAMP(6) - INTERVAL ? SECOND, ?, ?, ?, '203.0.113.10', 'mailpit-<prod>')");
        $insert->execute([1200, 70, 0, 0]);
        for ($ago = 900; $ago >= 0; $ago -= 30) {
            $insert->execute([$ago, $ago === 900 ? null : 85 + $ago % 60, 3, 1]);
        }
        self::assertSame(200, $this->ping()->getStatusCode(), 'le ping du relais passe par la même application');

        $response = $this->request('GET', '/v1/app/status', ip: self::OTHER_IP);
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('<meta http-equiv="refresh" content="60; url=/v1/app/status?auto=1">', $body);
        self::assertStringContainsString('class="active">Statut</a>', $body);
        self::assertStringContainsString('<span class="badge ok">Connecté</span>', $body);
        self::assertStringContainsString('instance « mailpit-&lt;prod&gt; »', $body, 'libellé échappé');
        self::assertStringContainsString("<dt>Dernière latence</dt>\n        <dd>85 ms</dd>", $body);
        self::assertStringContainsString("<dt>Mails en attente</dt>\n        <dd>3</dd>", $body);
        self::assertStringContainsString("<dt>Mails refusés</dt>\n        <dd>1 <span class=\"muted\">", $body);
        self::assertStringContainsString('depuis le premier ping, le ', $body);
        self::assertStringContainsString('<svg class="chart"', $body);
        self::assertStringContainsString('<desc id="latency-desc">', $body);
        self::assertStringContainsString('1 coupure(s) de plus de 90 secondes sans ping, 5 min au total sur 24 heures.', $body);
        self::assertStringNotContainsString('<script>', $body, 'aucun script en ligne');

        // Les autres pages n'ont pas de rechargement automatique.
        self::assertStringNotContainsString('http-equiv="refresh"', (string) $this->request('GET', '/v1/app/tokens', ip: self::OTHER_IP)->getBody());
    }

    public function testStatusAutoRefreshDoesNotExtendTheSession(): void
    {
        $this->signIn();
        $lastActivity = time() - 600;
        $_SESSION['last_activity'] = $lastActivity;

        // Rechargement automatique (?auto=1) : page servie, mais ce n'est pas une activité.
        self::assertSame(200, $this->browserRequest('/v1/app/status?auto=1')->getStatusCode());
        self::assertSame($lastActivity, $_SESSION['last_activity']);

        // Une vraie visite, elle, prolonge la session.
        self::assertSame(200, $this->browserRequest('/v1/app/status')->getStatusCode());
        self::assertGreaterThanOrEqual(time() - 5, $_SESSION['last_activity']);

        // Rechargement automatique après la durée d'inactivité : session expirée, comme pour une visite.
        $_SESSION['last_activity'] = time() - 365 * 86400;
        $response = $this->browserRequest('/v1/app/status?auto=1');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/v1/app/signin', $response->getHeaderLine('Location'));
    }

    /** Nouvelle requête du navigateur : la session est fermée, puis reprise grâce à son cookie. */
    private function browserRequest(string $path): ResponseInterface
    {
        $id = (string) session_id();
        session_write_close();
        session_id($id);

        return $this->request('GET', $path, ip: self::OTHER_IP);
    }

    /** Ping de santé du relais (POST /v1/health). */
    private function ping(): ResponseInterface
    {
        return $this->request('POST', '/v1/health', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'application/json',
            'Mailpit-Label' => 'mailpit-<prod>',
        ], '{"latency_ms":85,"pending":3,"failed":1}');
    }

    /** Taille actuelle du log des tests : ce qui est écrit ensuite se lit avec logSince(). */
    private static function logSize(): int
    {
        clearstatcache();

        return is_file(TempDir::logFile()) ? (int) filesize(TempDir::logFile()) : 0;
    }

    private static function logSince(int $offset): string
    {
        clearstatcache();

        return is_file(TempDir::logFile()) ? (string) file_get_contents(TempDir::logFile(), offset: $offset) : '';
    }

    /** Page HTML d'erreur du Dashboard : bon code, HTML, mêmes en-têtes de sécurité que les autres pages. */
    private static function assertErrorPage(int $status, ResponseInterface $response, string $path): string
    {
        self::assertSame($status, $response->getStatusCode(), $path);
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'), $path);
        foreach (SecurityHeaders::HEADERS as $name => $value) {
            self::assertSame($value, $response->getHeaderLine($name), $path . ' : ' . $name);
        }

        $body = (string) $response->getBody();
        self::assertStringStartsWith('<!doctype html>', ltrim($body), $path);
        self::assertStringContainsString('<a class="button" href="/v1/app/messages">Retour aux mails</a>', $body, $path);
        self::assertStringNotContainsString('{"message"', $body, $path);

        return $body;
    }

    public function testUnknownMessageShowsAnHtmlErrorPage(): void
    {
        $this->signIn();
        $offset = self::logSize();

        foreach (['/v1/app/messages/inconnu' => 'Mail inconnu.', '/v1/app/tokens/999' => 'Jeton inconnu.'] as $path => $message) {
            $body = self::assertErrorPage(404, $this->request('GET', $path, ip: self::OTHER_IP), $path);

            self::assertStringContainsString('<title>Page introuvable · Amnesic Mail</title>', $body, $path);
            self::assertStringContainsString('<p>' . $message . '</p>', $body, $path);
            // Administrateur connecté : le menu est là.
            self::assertStringContainsString('Déconnexion', $body, $path);
        }

        // Une 404 n'est pas une erreur du serveur : rien dans le log.
        self::assertSame('', self::logSince($offset));
    }

    public function testUnknownDashboardUrlWithoutSession(): void
    {
        $offset = self::logSize();

        foreach (['/v1/app/inconnue', '/v1/app/messages/m1/autre', '/v1/app/'] as $path) {
            $body = self::assertErrorPage(404, $this->request('GET', $path, ip: self::OTHER_IP), $path);

            self::assertStringContainsString('<h1>Page introuvable</h1>', $body, $path);
            self::assertStringContainsString('Cette page n&#039;existe pas', $body, $path);
            // Personne n'est connecté : ni menu, ni formulaire.
            self::assertStringNotContainsString('Déconnexion', $body, $path);
            self::assertStringNotContainsString('_csrf', $body, $path);
        }

        // Aucune session créée pour une URL inconnue sans cookie (robots, scans), et rien dans le log.
        self::assertNotSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame('', self::logSince($offset));
    }

    public function testUnknownDashboardUrlShowsTheMenuWhenTheSessionCookieIsSent(): void
    {
        $this->signIn();
        // Une nouvelle requête du navigateur : session fermée, seul le cookie la désigne.
        $id = (string) session_id();
        session_write_close();
        session_id($id);

        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://api.test/v1/app/inconnue', [
            'REMOTE_ADDR' => self::OTHER_IP,
            'HTTPS'       => 'on',
            'SERVER_PORT' => '443',
        ])->withCookieParams([Session::COOKIE => $id]);
        $body = self::assertErrorPage(404, $this->handle($request), '/v1/app/inconnue');

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertStringContainsString('Déconnexion', $body);
        self::assertStringContainsString('<span>admin</span>', $body);
    }

    public function testWrongMethodShowsAnHtmlErrorPage(): void
    {
        $offset = self::logSize();

        $response = $this->request('DELETE', '/v1/app/messages', ip: self::OTHER_IP);

        $body = self::assertErrorPage(405, $response, 'DELETE /v1/app/messages');
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertStringContainsString('<h1>Méthode non autorisée</h1>', $body);
        self::assertStringContainsString('Cette page n&#039;accepte pas la méthode DELETE (seulement GET).', $body);

        // OPTIONS : Slim répondrait 200 (pré-vérification CORS) ; la page garde le vrai code.
        $response = $this->request('OPTIONS', '/v1/app/messages', ip: self::OTHER_IP);
        $body = self::assertErrorPage(405, $response, 'OPTIONS /v1/app/messages');
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertStringContainsString('<p class="muted">Erreur 405</p>', $body);
        $body = self::assertErrorPage(404, $this->request('OPTIONS', '/v1/app/inconnue', ip: self::OTHER_IP), 'OPTIONS /v1/app/inconnue');
        self::assertStringContainsString('<h1>Page introuvable</h1>', $body);

        self::assertSame('', self::logSince($offset));
    }

    /**
     * Configuration avec une base injoignable (aucun serveur MySQL sur le port 1).
     *
     * @return array<string, mixed>
     */
    private function configWithoutDatabase(bool $debug = false): array
    {
        $config = $this->config();
        $config['db']['port'] = 1;
        $config['debug'] = $debug;

        return $config;
    }

    public function testDatabaseErrorShowsAnInternalErrorPageWithoutDetails(): void
    {
        $this->signIn();
        $this->useConfig($this->configWithoutDatabase());
        $offset = self::logSize();

        $body = self::assertErrorPage(500, $this->request('GET', '/v1/app/messages', ip: self::OTHER_IP), '/v1/app/messages');

        self::assertStringContainsString('<h1>Erreur interne</h1>', $body);
        self::assertStringContainsString('var/log/api.log', $body);
        self::assertStringNotContainsString('PDOException', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString("Détail de l'erreur", $body);
        // L'erreur, elle, est dans le log ; la page s'est affichée sans seconde erreur.
        $log = self::logSince($offset);
        self::assertStringContainsString('PDOException', $log);
        self::assertStringNotContainsString('[dashboard]', $log);
    }

    public function testDatabaseErrorWithoutSessionAndInDebug(): void
    {
        // Connexion avec une base injoignable : 500 avant toute connexion, et détail en mode debug.
        $this->useConfig($this->configWithoutDatabase(debug: true));
        $csrf = self::csrf($this->request('GET', '/v1/app/signin', ip: self::OTHER_IP));

        $response = $this->request('POST', '/v1/app/signin', ip: self::OTHER_IP, form: ['_csrf' => $csrf, 'username' => 'admin', 'password' => 'x']);

        $body = self::assertErrorPage(500, $response, 'POST /v1/app/signin');
        self::assertStringContainsString('<h1>Erreur interne</h1>', $body);
        self::assertStringContainsString("Détail de l'erreur (debug)", $body);
        self::assertStringContainsString('PDOException', $body);
        self::assertStringContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString('Déconnexion', $body);
    }

    public function testDeletedAdminIsSignedOut(): void
    {
        $this->signIn();
        $this->pdo->exec('DELETE FROM mailpit_admins');

        $response = $this->request('GET', '/v1/app/messages', ip: self::OTHER_IP);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/v1/app/signin', $response->getHeaderLine('Location'));
    }
}
