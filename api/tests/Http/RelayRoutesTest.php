<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\TempDir;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /v1/messages et POST /v1/messages/{id}/part/{partId} : réservés au relais.
 */
final class RelayRoutesTest extends AppTestCase
{
    public function testForbiddenIpIsRejectedBeforeTheToken(): void
    {
        $response = $this->request('POST', '/v1/messages', ['Content-Type' => 'application/json'], '{}', self::OTHER_IP);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'Forbidden'], self::json($response));

        // Même avec le bon jeton.
        $response = $this->request('POST', '/v1/messages', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'application/json',
        ], json_encode(self::payload('m1'), JSON_THROW_ON_ERROR), self::OTHER_IP);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));

        self::assertSame(403, $this->postPartFrom(self::OTHER_IP)->getStatusCode());
    }

    public function testIpv4MappedAddressIsAllowed(): void
    {
        $response = $this->request('POST', '/v1/messages', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'application/json',
        ], json_encode(self::payload('m1'), JSON_THROW_ON_ERROR), '::ffff:' . self::RELAY_IP);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testMissingOrWrongToken(): void
    {
        $response = $this->request('POST', '/v1/messages', ['Content-Type' => 'application/json'], '{}');
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(['error' => 'Unauthorized'], self::json($response));

        self::assertSame(401, $this->postMessage(self::payload('m1'), 'mauvais-jeton')->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));
    }

    public function testTokenInQueryStringForTheDirectWebhook(): void
    {
        $response = $this->request(
            'POST',
            '/v1/messages?token=' . self::RELAY_TOKEN,
            ['Content-Type' => 'application/json', 'Mailpit-Label' => 'mailpit-prod'],
            json_encode(array_diff_key(self::payload('m1'), ['Text' => 0, 'HTML' => 0, 'Headers' => 0]), JSON_THROW_ON_ERROR),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('mailpit-prod', $this->scalar('SELECT mailpit_label FROM mailpit_messages'));
    }

    public function testWrongContentType(): void
    {
        $response = $this->request('POST', '/v1/messages', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'text/plain',
        ], '{}');

        self::assertSame(415, $response->getStatusCode());
        self::assertSame(['error' => 'Content-Type application/json attendu'], self::json($response));
    }

    public function testContentTypeWithCharsetIsAccepted(): void
    {
        $response = $this->request('POST', '/v1/messages', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'Application/JSON; charset=utf-8',
        ], json_encode(self::payload('m1'), JSON_THROW_ON_ERROR));

        self::assertSame(201, $response->getStatusCode());
    }

    public function testInvalidJson(): void
    {
        $response = $this->request('POST', '/v1/messages', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'application/json',
        ], '{"ID": ');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'JSON invalide'], self::json($response));
    }

    public function testBodyTooLarge(): void
    {
        $headers = ['Authorization' => 'Bearer ' . self::RELAY_TOKEN, 'Content-Type' => 'application/json'];

        // Taille annoncée : refusé sans lire le corps.
        $response = $this->request('POST', '/v1/messages', $headers + ['Content-Length' => (string) (self::MAX_BODY_SIZE + 1)], '{}');
        self::assertSame(413, $response->getStatusCode());

        // Taille réelle, sans Content-Length.
        $payload = ['Text' => str_repeat('a', self::MAX_BODY_SIZE)] + self::payload('m1');
        $response = $this->postMessage($payload);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(['error' => 'corps de requête trop volumineux'], self::json($response));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_messages'));
    }

    public function testInvalidPayload(): void
    {
        $response = $this->postMessage(array_diff_key(self::payload('m1'), ['ID' => 0]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'champ « ID » manquant ou invalide'], self::json($response));

        self::assertSame(422, $this->postMessage([self::payload('m1')])->getStatusCode(), 'liste JSON');
    }

    public function testCreatedThenDuplicate(): void
    {
        $response = $this->postMessage(self::payload('m1'));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['id' => 'm1', 'status' => 'created', 'complete' => true, 'missing' => [], 'too_large' => []],
            self::json($response),
        );

        $response = $this->postMessage(self::payload('m1'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('duplicate', self::json($response)['status']);

        // Le corps, les en-têtes et le texte ne sont pas recopiés dans « payload ».
        $payload = json_decode((string) $this->scalar('SELECT payload FROM mailpit_messages'), true);
        self::assertSame('m1', $payload['ID']);
        self::assertArrayNotHasKey('Text', $payload);
        self::assertArrayNotHasKey('HTML', $payload);
        self::assertArrayNotHasKey('Headers', $payload);
        self::assertSame(self::RELAY_IP, $this->scalar('SELECT source_ip FROM mailpit_messages'));
    }

    public function testFullUploadFlow(): void
    {
        $small = "première pièce\n";
        $other = random_bytes(600);
        $response = $this->postMessage(self::payload('m1', parts: [['2', strlen($small)], ['1.3', strlen($other)], ['4', self::MAX_PART_SIZE + 1]]));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['id' => 'm1', 'status' => 'created', 'complete' => false, 'missing' => ['2', '1.3'], 'too_large' => ['4']],
            self::json($response),
        );

        $response = $this->postPart('m1', '2', $small);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['status' => 'stored', 'id' => 'm1', 'part' => '2', 'complete' => false, 'missing' => ['1.3'], 'too_large' => ['4']],
            self::json($response),
        );

        // Empreinte en majuscules : acceptée.
        $response = $this->postPart('m1', '1.3', $other, strtoupper(hash('sha256', $other)));
        self::assertSame(201, $response->getStatusCode());
        self::assertTrue(self::json($response)['complete']);

        self::assertSame(['m1/part-1.3', 'm1/part-2'], TempDir::files($this->storageDir));
        self::assertSame($small, file_get_contents($this->storageDir . '/m1/part-2'));
        self::assertSame($other, file_get_contents($this->storageDir . '/m1/part-1.3'));
        self::assertNotNull($this->scalar('SELECT completed_at FROM mailpit_messages'));

        // Même pièce renvoyée (relais redémarré) : rien ne change.
        $response = $this->postPart('m1', '2', $small);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('already-stored', self::json($response)['status']);
        self::assertTrue(self::json($response)['complete']);

        // Renvoi du mail complet : plus rien n'est attendu.
        $response = $this->postMessage(self::payload('m1', parts: [['2', strlen($small)], ['1.3', strlen($other)], ['4', self::MAX_PART_SIZE + 1]]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], self::json($response)['missing']);
        self::assertTrue(self::json($response)['complete']);
    }

    public function testWrongChecksumIsRejected(): void
    {
        $this->postMessage(self::payload('m1', parts: [['2', 5]]));

        $response = $this->postPart('m1', '2', 'hello', hash('sha256', 'autre chose'));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'empreinte SHA-256 différente du contenu reçu'], self::json($response));
        self::assertSame([], TempDir::files($this->storageDir), 'aucun fichier, même temporaire');
        self::assertSame('pending', $this->scalar('SELECT status FROM mailpit_parts'));

        $response = $this->request('POST', '/v1/messages/m1/part/2', [
            'Authorization' => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'  => 'application/octet-stream',
        ], 'hello');
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'en-tête X-Content-SHA256 absent ou invalide'], self::json($response));
    }

    public function testConflicts(): void
    {
        $this->postMessage(self::payload('m1', parts: [['2', 5], ['3', self::MAX_PART_SIZE + 1]]));

        $response = $this->postPart('m1', '3', 'x');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'pièce trop volumineuse : elle n\'est pas enregistrée'], self::json($response));

        $this->postPart('m1', '2', 'hello');
        $response = $this->postPart('m1', '2', 'HELLO');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'pièce déjà reçue avec un contenu différent'], self::json($response));
        self::assertSame('hello', file_get_contents($this->storageDir . '/m1/part-2'));
    }

    public function testUnknownMessageOrPart(): void
    {
        $this->postMessage(self::payload('m1', parts: [['2', 5]]));

        self::assertSame(404, $this->postPart('inconnu', '2', 'hello')->getStatusCode());
        $response = $this->postPart('m1', '9', 'hello');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'pièce inconnue : envoyez d\'abord le message (POST /v1/messages)'], self::json($response));
        self::assertSame(404, $this->postPart('m1', 'abc', 'hello')->getStatusCode(), 'PartID invalide');
    }

    public function testPartContentTypeAndSize(): void
    {
        $this->postMessage(self::payload('m1', parts: [['2', 5]]));

        $response = $this->request('POST', '/v1/messages/m1/part/2', [
            'Authorization'    => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'     => 'application/json',
            'X-Content-SHA256' => hash('sha256', 'hello'),
        ], 'hello');
        self::assertSame(415, $response->getStatusCode());

        // La taille annoncée était fausse : le contenu dépasse la limite.
        $content = str_repeat('a', self::MAX_PART_SIZE + 1);
        $response = $this->postPart('m1', '2', $content);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame([], TempDir::files($this->storageDir));
    }

    public function testRetentionDeletesOldMessagesAndFiles(): void
    {
        $config = $this->config();
        $config['webhook']['max_messages'] = 2;
        $this->useConfig($config);

        foreach (['m1', 'm2', 'm3'] as $id) {
            self::assertSame(201, $this->postMessage(self::payload($id, parts: [['2', 1]]))->getStatusCode());
            self::assertSame(201, $this->postPart($id, '2', 'x')->getStatusCode());
        }

        self::assertSame(['m2', 'm3'], $this->pdo->query('SELECT mailpit_id FROM mailpit_messages ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        // Le dossier de m1 a été effacé au 3e mail ; m3 a reçu sa pièce ensuite.
        self::assertSame(['m2/part-2', 'm3/part-2'], TempDir::files($this->storageDir));
    }

    public function testUnknownRouteIsJson(): void
    {
        $response = $this->request('GET', '/v1/inconnu');

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayHasKey('message', self::json($response));
        self::assertSame(405, $this->request('PUT', '/v1/messages')->getStatusCode());
    }

    private function postPartFrom(string $ip): ResponseInterface
    {
        return $this->request('POST', '/v1/messages/m1/part/2', [
            'Authorization'    => 'Bearer ' . self::RELAY_TOKEN,
            'Content-Type'     => 'application/octet-stream',
            'X-Content-SHA256' => hash('sha256', 'x'),
        ], 'x', $ip);
    }
}
