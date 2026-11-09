<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\AppTestCase;
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
        foreach (['/v1/app', '/v1/app/messages', '/v1/app/tokens', '/v1/app/messages/m1'] as $path) {
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
        self::assertStringNotContainsString('http://api.test', $body);
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
