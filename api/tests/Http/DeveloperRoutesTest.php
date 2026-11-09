<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Persistence\TokenRepository;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\TempDir;
use Psr\Http\Message\ResponseInterface;

/**
 * Routes des développeurs (jeton « mpt_… ») : GET /v1/messages, GET /v1/messages/{id},
 * GET /v1/addresses, DELETE.
 */
final class DeveloperRoutesTest extends AppTestCase
{
    /** Jeton associé à alice@test.fr et carol@test.fr. */
    private string $token;
    /** Jeton sans adresse. */
    private string $emptyToken;

    protected function setUp(): void
    {
        parent::setUp();

        $tokens = new TokenRepository($this->database);
        $created = $tokens->create('Projet A');
        $tokens->addAddress($created['id'], 'alice@test.fr');
        $tokens->addAddress($created['id'], 'carol@test.fr');
        $this->token = $created['token'];
        $this->emptyToken = $tokens->create('Vide')['token'];

        // Mails reçus par Mailpit dans l'ordre a1, b1, ab, a2 (a2 le plus récent).
        $this->postMessage(self::payload('a1', ['Alice@Test.fr'], [['2', 1]], '2026-11-08T10:00:00Z'));
        $this->postPart('a1', '2', 'x');
        $this->postMessage(self::payload('b1', ['bob@test.fr'], created: '2026-11-08T11:00:00Z'));
        $this->postMessage(self::payload('ab', ['alice@test.fr', 'bob@test.fr'], created: '2026-11-08T12:00:00Z'));
        $this->postMessage(self::payload('a2', ['alice@test.fr'], created: '2026-11-08T13:00:00Z'));
    }

    private function get(string $path, ?string $token = null, string $ip = self::OTHER_IP): ResponseInterface
    {
        return $this->request('GET', $path, ['Authorization' => 'Bearer ' . ($token ?? $this->token)], ip: $ip);
    }

    private function delete(string $path, ?string $token = null): ResponseInterface
    {
        return $this->request('DELETE', $path, ['Authorization' => 'Bearer ' . ($token ?? $this->token)], ip: self::OTHER_IP);
    }

    public function testListOnlyShowsTheTokenAddresses(): void
    {
        $response = $this->get('/v1/messages');

        self::assertSame(200, $response->getStatusCode());
        $data = self::json($response);
        self::assertSame(3, $data['total']);
        self::assertSame(3, $data['count']);
        self::assertSame(5, $data['limit']);
        self::assertSame(0, $data['offset']);
        self::assertSame(1, $data['page']);
        self::assertSame(['a2', 'ab', 'a1'], array_column($data['messages'], 'id'));
        // Résumés : ni corps ni en-têtes ; les pièces sont décrites, jamais le chemin des fichiers.
        $a1 = $data['messages'][2];
        self::assertSame('Sujet a1', $a1['subject']);
        self::assertSame('Début du texte', $a1['snippet']);
        self::assertTrue($a1['complete']);
        foreach (['text', 'html', 'headers'] as $key) {
            self::assertArrayNotHasKey($key, $a1);
        }
        self::assertSame('stored', $a1['parts'][0]['status']);
        self::assertArrayNotHasKey('storage_path', $a1['parts'][0]);
    }

    public function testShowMessage(): void
    {
        $list = self::json($this->get('/v1/messages?limit=25'));
        $response = $this->get('/v1/messages/a1');

        self::assertSame(200, $response->getStatusCode());
        $data = self::json($response);
        // Les champs de la liste, plus le corps et les en-têtes.
        $summary = $list['messages'][array_search('a1', array_column($list['messages'], 'id'), true)];
        self::assertSame($summary, array_diff_key($data, array_flip(['text', 'html', 'headers'])));
        self::assertSame('Texte de a1', $data['text']);
        self::assertSame('<p>HTML de a1</p>', $data['html']);
        self::assertSame(['Subject' => ['Sujet a1']], $data['headers']);
        self::assertSame('fichier-2.txt', $data['parts'][0]['file_name']);
        self::assertArrayNotHasKey('storage_path', $data['parts'][0]);
        self::assertStringNotContainsString('part-2', (string) $response->getBody(), 'aucun chemin de fichier');

        // Reçu aussi par une autre adresse : visible.
        self::assertSame('ab', self::json($this->get('/v1/messages/ab'))['id']);
    }

    public function testShowMessageWithoutHeaders(): void
    {
        $payload = self::payload('nh', ['alice@test.fr']);
        unset($payload['Headers'], $payload['HTML']);
        $this->postMessage($payload);

        $response = $this->get('/v1/messages/nh');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"headers":{}', (string) $response->getBody(), 'toujours un objet JSON');
        self::assertNull(self::json($response)['html']);
    }

    public function testShowMessageOutsideTheTokenAddresses(): void
    {
        // Même réponse pour un mail d'une autre adresse et un mail inconnu : son existence n'est pas révélée.
        foreach (['b1', 'inconnu', 'id.invalide', str_repeat('a', 65)] as $id) {
            $response = $this->get('/v1/messages/' . $id);
            self::assertSame(404, $response->getStatusCode(), $id);
            self::assertSame(['error' => 'mail inconnu'], self::json($response), $id);
        }
        self::assertSame(404, $this->get('/v1/messages/a1', $this->emptyToken)->getStatusCode(), 'jeton sans adresse');
        self::assertSame(404, $this->get('/v1/messages/')->getStatusCode(), 'sans ID');

        // Destinataire en Bcc seulement : visible.
        $payload = self::payload('bcc', ['bob@test.fr']);
        $payload['Bcc'] = [['Name' => '', 'Address' => 'carol@test.fr']];
        $this->postMessage($payload);
        self::assertSame(200, $this->get('/v1/messages/bcc')->getStatusCode());
    }

    public function testListPagination(): void
    {
        $data = self::json($this->get('/v1/messages?limit=2&page=2'));
        self::assertSame(['a1'], array_column($data['messages'], 'id'));
        self::assertSame(2, $data['offset']);
        self::assertSame(2, $data['page']);

        $data = self::json($this->get('/v1/messages?limit=1&offset=1'));
        self::assertSame(['ab'], array_column($data['messages'], 'id'));
        self::assertSame(2, $data['page']);
    }

    public function testListOneAddress(): void
    {
        $data = self::json($this->get('/v1/messages?address=' . rawurlencode(' ALICE@test.fr')));
        self::assertSame(3, $data['total']);

        $data = self::json($this->get('/v1/messages?address=carol@test.fr'));
        self::assertSame(0, $data['total']);
        self::assertSame([], $data['messages']);

        $response = $this->get('/v1/messages?address=bob@test.fr');
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'l\'adresse « bob@test.fr » n\'est pas associée à ce jeton'], self::json($response));
    }

    public function testListInvalidParameters(): void
    {
        foreach (['limit=0', 'limit=26', 'limit=abc', 'page=0', 'offset=-1', 'offset=1&page=2', 'address='] as $query) {
            $response = $this->get('/v1/messages?' . $query);
            self::assertSame(400, $response->getStatusCode(), $query);
            self::assertArrayHasKey('error', self::json($response));
        }
    }

    public function testTokenWithoutAddressSeesNothing(): void
    {
        $data = self::json($this->get('/v1/messages', $this->emptyToken));

        self::assertSame(0, $data['total']);
        self::assertSame([], $data['messages']);
    }

    public function testMissingOrUnknownToken(): void
    {
        $response = $this->request('GET', '/v1/messages', ip: self::OTHER_IP);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));

        self::assertSame(401, $this->get('/v1/messages', 'mpt_inconnu')->getStatusCode());
        self::assertSame(401, $this->get('/v1/messages/a1', 'mpt_inconnu')->getStatusCode());
        self::assertSame(401, $this->request('GET', '/v1/messages/a1', ip: self::OTHER_IP)->getStatusCode());
        self::assertSame(401, $this->get('/v1/addresses', 'mpt_inconnu')->getStatusCode());
        self::assertSame(401, $this->delete('/v1/messages/a1', 'mpt_inconnu')->getStatusCode());
        self::assertSame(401, $this->delete('/v1/addresses?address=alice@test.fr', 'mpt_inconnu')->getStatusCode());
    }

    public function testRelayTokenListsEverythingFromAllowedIpsOnly(): void
    {
        $data = self::json($this->get('/v1/messages?limit=25', self::RELAY_TOKEN, self::RELAY_IP));
        self::assertSame(4, $data['total']);

        $data = self::json($this->get('/v1/messages?address=bob@test.fr', self::RELAY_TOKEN, self::RELAY_IP));
        self::assertSame(['ab', 'b1'], array_column($data['messages'], 'id'));

        // Un mail complet, quelles que soient ses adresses.
        $response = $this->get('/v1/messages/b1', self::RELAY_TOKEN, self::RELAY_IP);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Texte de b1', self::json($response)['text']);
        self::assertSame(404, $this->get('/v1/messages/inconnu', self::RELAY_TOKEN, self::RELAY_IP)->getStatusCode());

        $response = $this->get('/v1/messages', self::RELAY_TOKEN, self::OTHER_IP);
        self::assertSame(403, $response->getStatusCode());
        $response = $this->get('/v1/messages/b1', self::RELAY_TOKEN, self::OTHER_IP);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'Forbidden'], self::json($response));

        // Le jeton du relais ne sert qu'à la lecture des mails : pas aux autres routes des développeurs.
        self::assertSame(401, $this->get('/v1/addresses', self::RELAY_TOKEN, self::RELAY_IP)->getStatusCode());
        self::assertSame(401, $this->delete('/v1/messages/b1', self::RELAY_TOKEN)->getStatusCode());
    }

    public function testAddresses(): void
    {
        $response = $this->get('/v1/addresses');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'count'     => 2,
            'addresses' => [
                ['address' => 'alice@test.fr', 'messages' => 3],
                ['address' => 'carol@test.fr', 'messages' => 0],
            ],
        ], self::json($response));

        self::assertSame(['count' => 0, 'addresses' => []], self::json($this->get('/v1/addresses', $this->emptyToken)));
    }

    public function testDeleteMessage(): void
    {
        self::assertSame(['a1/part-2'], TempDir::files($this->storageDir));

        $response = $this->delete('/v1/messages/b1');
        self::assertSame(404, $response->getStatusCode(), 'mail d\'une autre adresse');
        self::assertSame(['error' => 'mail inconnu'], self::json($response));
        self::assertSame(404, $this->delete('/v1/messages/inconnu')->getStatusCode());
        self::assertSame(404, $this->delete('/v1/messages/a1', $this->emptyToken)->getStatusCode());

        $response = $this->delete('/v1/messages/a1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 'a1', 'deleted' => true], self::json($response));
        self::assertSame([], TempDir::files($this->storageDir), 'pièces effacées du disque');
        self::assertFalse(is_dir($this->storageDir . '/a1'));
        self::assertSame(404, $this->delete('/v1/messages/a1')->getStatusCode());

        // Un mail reçu aussi par une autre adresse peut être supprimé.
        self::assertSame(200, $this->delete('/v1/messages/ab')->getStatusCode());
        self::assertSame(['a2', 'b1'], $this->pdo->query('SELECT mailpit_id FROM mailpit_messages ORDER BY mailpit_id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testDeleteAddressMessages(): void
    {
        $response = $this->delete('/v1/addresses?address=' . rawurlencode('Alice@test.fr'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['address' => 'alice@test.fr', 'deleted' => 3], self::json($response));
        self::assertSame(['b1'], $this->pdo->query('SELECT mailpit_id FROM mailpit_messages')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([], TempDir::files($this->storageDir));
        // L'adresse reste associée au jeton.
        self::assertSame(2, self::json($this->get('/v1/addresses'))['count']);

        self::assertSame(['address' => 'carol@test.fr', 'deleted' => 0], self::json($this->delete('/v1/addresses?address=carol@test.fr')));

        $response = $this->delete('/v1/addresses?address=bob@test.fr');
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));

        $response = $this->delete('/v1/addresses');
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'paramètre « address » obligatoire'], self::json($response));
    }
}
