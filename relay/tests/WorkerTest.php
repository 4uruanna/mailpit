<?php

declare(strict_types=1);

namespace Relay\Tests;

use PHPUnit\Framework\TestCase;
use Relay\Tests\Support\FakeState;
use Relay\Tests\Support\PhpServer;
use RuntimeException;

/**
 * Worker de bout en bout : un faux Mailpit et une fausse API (serveurs « php -S », état partagé
 * dans un fichier temporaire), et le Worker lancé dans un processus séparé (tests/Fixtures/run-worker.php).
 */
final class WorkerTest extends TestCase
{
    private const string TOKEN = 'jeton-du-relais-0123456789abcdef0123456789abcdef';
    private const string MAILPIT_AUTH = 'admin:MotDePasseSolide';

    private FakeState $state;
    private PhpServer $mailpit;
    private PhpServer $api;

    protected function setUp(): void
    {
        $this->state = FakeState::create([
            'mailpit'  => ['auth' => self::MAILPIT_AUTH, 'messages' => []],
            'api'      => [
                'token'          => self::TOKEN,
                'max_part_size'  => 5 * 1024 * 1024,
                'message_status' => null,
                'part_status'    => null,
                'messages'       => [],
                'parts'          => [],
            ],
            'requests' => [],
        ]);

        $env = ['FAKE_STATE' => $this->state->file];
        $this->mailpit = new PhpServer(__DIR__ . '/Fixtures/mailpit-router.php', $env);
        $this->api = new PhpServer(__DIR__ . '/Fixtures/api-router.php', $env);
    }

    protected function tearDown(): void
    {
        $this->mailpit->stop();
        $this->api->stop();
        $this->state->delete();
    }

    public function testDeliversTheFullMessageThenTagsIt(): void
    {
        $logo = random_bytes(3000);
        $pdf = random_bytes(20000);
        $this->addMessage('m1', [
            ['PartID' => '1.2', 'Kind' => 'inline', 'FileName' => 'logo.png', 'ContentType' => 'image/png', 'ContentID' => 'logo123', 'content' => $logo],
            ['PartID' => '2', 'Kind' => 'attachment', 'FileName' => 'devis.pdf', 'ContentType' => 'application/pdf', 'ContentID' => '', 'content' => $pdf],
        ], ['Tags' => ['important']]);

        $run = $this->runWorker();

        self::assertSame([false], $run['syncs']);
        self::assertStringContainsString('mail m1 enregistré par l\'API (Sujet m1), 2 pièce(s) envoyée(s), essai 1', $run['log']);

        // Le mail complet est arrivé à l'API, avec le libellé de l'instance.
        $state = $this->state->read();
        $sent = $state['api']['messages']['m1'];
        self::assertSame('Sujet m1', $sent['Subject']);
        self::assertSame(['important'], $sent['Tags']);
        self::assertSame('Texte de m1', $sent['Text']);
        self::assertSame('<p>HTML de m1 <img src="cid:logo123"></p>', $sent['HTML']);
        self::assertSame('bounce@example.com', $sent['ReturnPath']);
        self::assertSame('2026-11-08T21:15:01+01:00', $sent['Date']);
        self::assertSame(['Subject' => ['Sujet m1'], 'Received' => ['from a', 'from b']], $sent['Headers']);
        self::assertArrayNotHasKey('Read', $sent, '« Read » est propre à Mailpit');
        self::assertSame('test-relay', $sent['_label']);
        self::assertSame([
            ['PartID' => '1.2', 'Kind' => 'inline', 'FileName' => 'logo.png', 'ContentType' => 'image/png', 'ContentID' => 'logo123', 'Size' => 3000],
            ['PartID' => '2', 'Kind' => 'attachment', 'FileName' => 'devis.pdf', 'ContentType' => 'application/pdf', 'ContentID' => '', 'Size' => 20000],
        ], $sent['Parts'], 'sans l\'empreinte de Mailpit');

        // Pièces envoyées une à une, avec la bonne empreinte.
        self::assertSame([
            'm1/1.2' => ['sha256' => hash('sha256', $logo), 'size' => 3000],
            'm1/2'   => ['sha256' => hash('sha256', $pdf), 'size' => 20000],
        ], $state['api']['parts']);

        // Tag posé (en gardant les tags existants), mail remis en « non lu ».
        $message = $state['mailpit']['messages']['m1'];
        self::assertSame(['important', 'relayed'], $message['Tags']);
        self::assertFalse($message['Read']);

        // Dans l'ordre : le mail, ses pièces, puis seulement le tag.
        self::assertSame([
            'mailpit GET /api/v1/search',
            'mailpit GET /api/v1/message/m1',
            'mailpit GET /api/v1/message/m1/headers',
            'api POST /v1/messages',
            'mailpit GET /api/v1/message/m1/part/1.2',
            'api POST /v1/messages/m1/part/1.2',
            'mailpit GET /api/v1/message/m1/part/2',
            'api POST /v1/messages/m1/part/2',
            'mailpit PUT /api/v1/tags',
            'mailpit PUT /api/v1/messages',
        ], $this->requests());
        $search = $state['requests'][0]['query'];
        self::assertSame('!tag:relayed !tag:relay-failed', $search['query']);
        $tagRequest = json_decode($state['requests'][8]['body'], true);
        self::assertSame(['IDs' => ['m1'], 'Tags' => ['important', 'relayed']], $tagRequest);
        // Toujours avec IDs : sans liste, Mailpit modifierait TOUS les mails.
        self::assertSame(['IDs' => ['m1'], 'Read' => false], json_decode($state['requests'][9]['body'], true));

        // Plus rien à relayer au passage suivant.
        $this->clearRequests();
        self::assertSame([false], $this->runWorker()['syncs']);
        self::assertSame(['mailpit GET /api/v1/search'], $this->requests());
    }

    public function testMessageAlreadyReadStaysRead(): void
    {
        $this->addMessage('m1', summary: ['Read' => true]);

        $this->runWorker();

        $state = $this->state->read();
        self::assertSame(['relayed'], $state['mailpit']['messages']['m1']['Tags']);
        self::assertTrue($state['mailpit']['messages']['m1']['Read']);
        self::assertNotContains('mailpit PUT /api/v1/messages', $this->requests());
    }

    public function testKeepUnreadCanBeDisabled(): void
    {
        $this->addMessage('m1');

        $this->runWorker(env: ['RELAY_KEEP_UNREAD' => 'false']);

        self::assertTrue($this->state->read()['mailpit']['messages']['m1']['Read']);
        self::assertNotContains('mailpit PUT /api/v1/messages', $this->requests());
    }

    public function testPermanentRefusalTagsTheMessageAsFailed(): void
    {
        $this->addMessage('m1', summary: ['Tags' => ['important']]);
        $this->setApi('message_status', 422);

        $run = $this->runWorker(2);

        self::assertSame([false, false], $run['syncs']);
        self::assertStringContainsString('mail m1 refusé par l\'API', $run['log']);
        self::assertStringContainsString('tag « relay-failed » posé dans Mailpit', $run['log']);
        self::assertSame(['important', 'relay-failed'], $this->state->read()['mailpit']['messages']['m1']['Tags']);
        // Pas de nouvel essai : le mail n'est plus dans les résultats de recherche.
        self::assertSame(1, $this->countRequests('api POST /v1/messages'));
    }

    public function testPermanentPartRefusalTagsTheMessageAsFailed(): void
    {
        $this->addMessage('m1', [['PartID' => '2', 'Kind' => 'attachment', 'content' => 'contenu']]);
        $this->setApi('part_status', 409);

        $this->runWorker();

        self::assertSame(['relay-failed'], $this->state->read()['mailpit']['messages']['m1']['Tags']);
    }

    public function testTransientErrorIsRetriedLaterWithoutTag(): void
    {
        $this->addMessage('m1');
        $this->setApi('message_status', 500);

        // Deux passages de suite : le second ne renvoie pas le mail (nouvel essai dans 30 s).
        $run = $this->runWorker(2);

        self::assertSame([false, false], $run['syncs']);
        self::assertStringContainsString('mail m1 : échec de l\'essai 1 (API (message) : HTTP 500', $run['log']);
        self::assertStringContainsString('nouvel essai dans 30 s', $run['log']);
        self::assertSame(1, $this->countRequests('api POST /v1/messages'));
        self::assertSame(2, $this->countRequests('mailpit GET /api/v1/search'));
        self::assertSame([], $this->state->read()['mailpit']['messages']['m1']['Tags']);
        self::assertNotContains('mailpit PUT /api/v1/tags', $this->requests());

        // L'API répond de nouveau (relais redémarré : l'attente est oubliée) : le mail est livré.
        $this->setApi('message_status', null);
        $this->runWorker();

        self::assertSame(['relayed'], $this->state->read()['mailpit']['messages']['m1']['Tags']);
    }

    public function testUnauthorizedIsTransient(): void
    {
        $this->addMessage('m1');

        $run = $this->runWorker(env: ['RELAY_API_TOKEN' => str_repeat('x', 40)]);

        self::assertStringContainsString('HTTP 401', $run['log']);
        self::assertSame([], $this->state->read()['mailpit']['messages']['m1']['Tags']);
    }

    public function testChecksumMismatchFromMailpitIsTransient(): void
    {
        $this->addMessage('m1', [['PartID' => '2', 'Kind' => 'attachment', 'content' => 'contenu', 'sha256' => hash('sha256', 'autre')]]);

        $run = $this->runWorker();

        self::assertStringContainsString('pièce 2 : empreinte différente de celle annoncée par Mailpit', $run['log']);
        self::assertSame(0, $this->countRequests('api POST /v1/messages/m1/part/2'));
        self::assertSame([], $this->state->read()['mailpit']['messages']['m1']['Tags']);
    }

    public function testTooLargePartsAreNotSent(): void
    {
        $this->addMessage('m1', [
            ['PartID' => '2', 'Kind' => 'attachment', 'FileName' => 'petit.txt', 'content' => 'petit'],
            ['PartID' => '3', 'Kind' => 'attachment', 'FileName' => 'gros.bin', 'content' => str_repeat('a', 2000)],
        ]);
        $this->setApi('max_part_size', 1000);

        $run = $this->runWorker();

        self::assertStringContainsString('pièce 3 « gros.bin » (2000 octets) au-delà de la limite de l\'API', $run['log']);
        self::assertSame(['m1/2'], array_keys($this->state->read()['api']['parts']));
        self::assertSame(0, $this->countRequests('mailpit GET /api/v1/message/m1/part/3'));
        self::assertSame(['relayed'], $this->state->read()['mailpit']['messages']['m1']['Tags']);
    }

    public function testBatchesAreProcessedOldestFirst(): void
    {
        $this->addMessage('ancien');
        $this->addMessage('moyen');
        $this->addMessage('recent');

        // Lots de 2 : le premier passage signale qu'il en reste.
        $run = $this->runWorker(2, ['RELAY_BATCH_SIZE' => '2']);

        self::assertSame([true, false], $run['syncs']);
        // Mailpit renvoie les plus récents d'abord : le Worker lit d'abord le lot des plus anciens.
        self::assertSame(['ancien', 'moyen', 'recent'], array_keys($this->state->read()['api']['messages']));
    }

    public function testBacklogIsProcessedOldestFirstAcrossBatches(): void
    {
        foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $id) {
            $this->addMessage($id);
        }

        $run = $this->runWorker(3, ['RELAY_BATCH_SIZE' => '2']);

        // Deux mails par passage, toujours les plus anciens restants.
        self::assertSame([true, true, false], $run['syncs']);
        self::assertSame(['m1', 'm2', 'm3', 'm4', 'm5'], array_keys($this->state->read()['api']['messages']));
        self::assertSame(5, $this->countRequests('mailpit PUT /api/v1/tags'));

        // Recherches : le total (les plus récents), puis le lot des plus anciens, à chaque passage.
        $searches = array_map(
            static fn (array $request): string => $request['query']['start'] . '+' . $request['query']['limit'],
            array_values(array_filter($this->state->read()['requests'], static fn (array $request): bool => $request['path'] === '/api/v1/search')),
        );
        self::assertSame(['0+2', '3+2', '0+2', '1+2', '0+2'], $searches);
    }

    public function testDeferredOldestBatchDoesNotBlockNewerMessages(): void
    {
        foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $id) {
            $this->addMessage($id);
        }
        // Les deux plus anciens échouent : nouvel essai dans 30 s.
        $this->setApi('failing', ['m1' => 500, 'm2' => 503]);

        $run = $this->runWorker(4, ['RELAY_BATCH_SIZE' => '2']);

        // 1er passage : m1 et m2 échouent. 2e : leur lot est entièrement en attente, le Worker
        // passe au lot suivant (m3, m4). 3e : m5. 4e : il ne reste que m1 et m2, en attente.
        self::assertSame([true, true, false, false], $run['syncs']);
        self::assertSame(['m3', 'm4', 'm5'], array_keys($this->state->read()['api']['messages']));
        self::assertSame(5, $this->countRequests('api POST /v1/messages'), 'm1 et m2 essayés une seule fois chacun');
        self::assertStringContainsString('mail m1 : échec de l\'essai 1', $run['log']);
        self::assertStringContainsString('mail m2 : échec de l\'essai 1', $run['log']);
        self::assertStringNotContainsString('essai 2', $run['log']);

        $messages = $this->state->read()['mailpit']['messages'];
        self::assertSame([], $messages['m1']['Tags']);
        self::assertSame([], $messages['m2']['Tags']);
        self::assertSame(['relayed'], $messages['m5']['Tags']);
    }

    public function testMessageArrivingDuringAPassTriggersAnotherPass(): void
    {
        foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $id) {
            $this->addMessage($id);
        }
        // m6 arrive dans Mailpit pendant que le Worker relaie m3 : les positions se décalent.
        $this->addMessage('m6', summary: ['_arrives_after' => 'm3']);
        $this->setApi('failing', ['m1' => 500]);

        $run = $this->runWorker(3, ['RELAY_BATCH_SIZE' => '2']);

        // 1er passage : m1 (échec) et m2. 2e : m1 en attente, m3, puis le lot [m6, m5] lu après
        // l'arrivée de m6 : m4 a sauté, le décalage est repéré et le passage suivant est immédiat.
        // 3e : m4.
        self::assertSame([true, true, false], $run['syncs']);
        self::assertSame(['m2', 'm3', 'm5', 'm6', 'm4'], array_keys($this->state->read()['api']['messages']));
        self::assertStringNotContainsString('essai 2', $run['log'], 'm1 attend toujours son nouvel essai');
    }

    public function testMessageDeletedFromMailpitIsSkipped(): void
    {
        $this->addMessage('m1', summary: ['_gone' => true]);

        $run = $this->runWorker();

        self::assertStringContainsString('mail m1 supprimé de Mailpit avant d\'avoir été relayé', $run['log']);
        self::assertSame(0, $this->countRequests('api POST /v1/messages'));
    }

    public function testMailpitUnreachable(): void
    {
        $this->addMessage('m1');

        $run = $this->runWorker(env: ['RELAY_MAILPIT_URL' => 'http://127.0.0.1:' . PhpServer::freePort()]);

        self::assertSame([false], $run['syncs']);
        self::assertStringContainsString('recherche des mails à relayer impossible', $run['log']);
    }

    public function testWrongMailpitCredentials(): void
    {
        $this->addMessage('m1');

        $run = $this->runWorker(env: ['RELAY_MAILPIT_AUTH' => 'admin:faux']);

        self::assertStringContainsString('Mailpit /api/v1/search : HTTP 401', $run['log']);
        self::assertSame(0, $this->countRequests('api POST /v1/messages'));
    }

    /**
     * Ajoute un mail dans le faux Mailpit (après les autres : c'est le plus récent).
     *
     * @param list<array{PartID: string, Kind: string, content: string, FileName?: string, ContentType?: string, ContentID?: string, sha256?: string}> $parts
     * @param array<string, mixed> $summary champs du résumé à remplacer
     */
    private function addMessage(string $id, array $parts = [], array $summary = []): void
    {
        $describe = static fn (array $part): array => [
            'PartID'      => $part['PartID'],
            'FileName'    => $part['FileName'] ?? 'fichier-' . $part['PartID'],
            'ContentType' => $part['ContentType'] ?? 'application/octet-stream',
            'ContentID'   => $part['ContentID'] ?? '',
            'Size'        => strlen($part['content']),
            'Checksums'   => ['MD5' => md5($part['content']), 'SHA256' => $part['sha256'] ?? hash('sha256', $part['content'])],
        ];
        $ofKind = static fn (string $kind): array => array_values(array_map(
            $describe,
            array_filter($parts, static fn (array $part): bool => $part['Kind'] === $kind),
        ));

        $message = $summary + [
            'ID'          => $id,
            'MessageID'   => $id . '@example.com',
            'Read'        => false,
            'From'        => ['Name' => 'Alice', 'Address' => 'alice@example.com'],
            'To'          => [['Name' => '', 'Address' => 'test@test.mondomaine.fr']],
            'Cc'          => [],
            'Bcc'         => [],
            'ReplyTo'     => [],
            'Subject'     => 'Sujet ' . $id,
            'Created'     => '2026-11-08T21:15:03.123+01:00',
            'Username'    => '',
            'Tags'        => [],
            'Size'        => 1234 + array_sum(array_map(static fn (array $part): int => strlen($part['content']), $parts)),
            'Attachments' => count($ofKind('attachment')),
            'Snippet'     => 'Texte de ' . $id,
            '_message'    => [
                'ReturnPath'  => 'bounce@example.com',
                'Date'        => '2026-11-08T21:15:01+01:00',
                'Text'        => 'Texte de ' . $id,
                'HTML'        => '<p>HTML de ' . $id . ' <img src="cid:logo123"></p>',
                'Inline'      => $ofKind('inline'),
                'Attachments' => $ofKind('attachment'),
            ],
            '_headers'    => ['Subject' => ['Sujet ' . $id], 'Received' => ['from a', 'from b']],
            '_parts'      => array_combine(
                array_column($parts, 'PartID'),
                array_map(static fn (array $part): string => base64_encode($part['content']), $parts),
            ),
        ];

        $this->state->update(static function (array &$state) use ($id, $message): void {
            $state['mailpit']['messages'][$id] = $message;
        });
    }

    private function setApi(string $key, mixed $value): void
    {
        $this->state->update(static function (array &$state) use ($key, $value): void {
            $state['api'][$key] = $value;
        });
    }

    /**
     * Requêtes reçues par les faux serveurs, dans l'ordre : « serveur MÉTHODE chemin ».
     *
     * @return list<string>
     */
    private function requests(): array
    {
        return array_map(
            static fn (array $request): string => sprintf('%s %s %s', $request['server'], $request['method'], $request['path']),
            $this->state->read()['requests'] ?? [],
        );
    }

    private function countRequests(string $request): int
    {
        return count(array_keys($this->requests(), $request, true));
    }

    private function clearRequests(): void
    {
        $this->state->update(static function (array &$state): void {
            $state['requests'] = [];
        });
    }

    /**
     * Lance tests/Fixtures/run-worker.php (N passages du Worker) avec la configuration des faux serveurs.
     *
     * @param array<string, string> $env variables RELAY_… à remplacer
     * @return array{syncs: list<bool>, log: string}
     */
    private function runWorker(int $syncs = 1, array $env = []): array
    {
        $stdout = (string) tempnam(sys_get_temp_dir(), 'relay-out-');
        $stderr = (string) tempnam(sys_get_temp_dir(), 'relay-err-');

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/run-worker.php', (string) $syncs],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
            null,
            [
                // Sans les RELAY_… de l'environnement (shell du développeur) : seuls ceux du test comptent.
                ...array_filter(getenv(), static fn (string $name): bool => !str_starts_with($name, 'RELAY_'), ARRAY_FILTER_USE_KEY),
                'RELAY_API_URL'      => $this->api->url(),
                'RELAY_API_TOKEN'    => self::TOKEN,
                'RELAY_MAILPIT_URL'  => $this->mailpit->url(),
                'RELAY_MAILPIT_AUTH' => self::MAILPIT_AUTH,
                'RELAY_LABEL'        => 'test-relay',
                'RELAY_TIMEOUT'      => '10',
                ...$env,
            ],
        );
        if ($process === false) {
            throw new RuntimeException('impossible de lancer run-worker.php');
        }
        $exitCode = proc_close($process);

        $output = (string) file_get_contents($stdout);
        $log = (string) file_get_contents($stderr);
        @unlink($stdout);
        @unlink($stderr);

        self::assertSame(0, $exitCode, "run-worker.php a échoué :\n" . $output . $log);
        $results = json_decode($output, true);
        self::assertIsArray($results, "sortie inattendue de run-worker.php :\n" . $output . $log);

        return ['syncs' => $results, 'log' => $log];
    }
}
