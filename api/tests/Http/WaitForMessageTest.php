<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SystemWaitClock;
use App\Persistence\TokenRepository;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\FakeWaitClock;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /v1/messages?wait=…&since=… : attendre l'arrivée d'un mail. Fausse horloge (FakeWaitClock) :
 * les pauses avancent le temps sans attendre, et les mails « arrivent » pendant ces pauses.
 */
final class WaitForMessageTest extends AppTestCase
{
    /** Jeton associé à alice@test.fr. */
    private string $token;
    private FakeWaitClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $tokens = new TokenRepository($this->database);
        $created = $tokens->create('Projet A');
        $tokens->addAddress($created['id'], 'alice@test.fr');
        $this->token = $created['token'];

        $this->clock = new FakeWaitClock();
        $this->waitClock = $this->clock;

        // Déjà là avant l'attente : un mail d'alice et un de bob, reçus par Mailpit le 8 novembre.
        $this->postMessage(self::payload('ancien', ['alice@test.fr'], created: '2026-11-08T10:00:00Z'));
        $this->postMessage(self::payload('bob', ['bob@test.fr'], created: '2026-11-09T10:00:00Z'));
    }

    private function get(string $query, ?string $token = null, string $ip = self::OTHER_IP): ResponseInterface
    {
        return $this->request('GET', '/v1/messages?' . $query, ['Authorization' => 'Bearer ' . ($token ?? $this->token)], ip: $ip);
    }

    public function testWaitWithoutNewMessageReturnsAfterTheDelay(): void
    {
        $response = $this->get('address=alice@test.fr&since=2026-11-09T08:00:00Z&wait=3');

        self::assertSame(200, $response->getStatusCode());
        $data = self::json($response);
        // Format habituel, avec « count » à 0, plus « waited_ms ».
        self::assertSame(
            ['total' => 0, 'count' => 0, 'limit' => 5, 'offset' => 0, 'page' => 1, 'waited_ms' => 3000, 'messages' => []],
            $data,
        );
        // Une vérification par seconde, la dernière à la fin du délai.
        self::assertSame([1.0, 1.0, 1.0], $this->clock->sleeps);
    }

    public function testMessageArrivingDuringTheWaitEndsItEarly(): void
    {
        $this->clock->onSleep(function (int $sleep): void {
            if ($sleep === 2) {
                $this->postMessage(self::payload('nouveau', ['alice@test.fr'], created: '2026-11-09T14:03:05.250Z'));
            }
        });

        $data = self::json($this->get('address=alice@test.fr&since=2026-11-09T14:03:00Z&wait=60'));

        self::assertSame(2000, $data['waited_ms']);
        self::assertCount(2, $this->clock->sleeps, 'retour dès la vérification qui suit l\'arrivée');
        self::assertSame(1, $data['total']);
        self::assertSame(['nouveau'], array_column($data['messages'], 'id'));
        self::assertTrue($data['messages'][0]['complete']);
    }

    public function testMailOfAnotherAddressDoesNotEndTheWait(): void
    {
        $this->clock->onSleep(function (int $sleep): void {
            if ($sleep === 1) {
                $this->postMessage(self::payload('pour-bob', ['bob@test.fr'], created: '2026-11-09T14:03:01Z'));
            }
        });

        $data = self::json($this->get('since=2026-11-09T14:03:00Z&wait=2'));

        self::assertSame(0, $data['count']);
        self::assertSame(2000, $data['waited_ms']);
    }

    public function testIncompleteMessageIsIgnoredUntilComplete(): void
    {
        $this->clock->onSleep(function (int $sleep): void {
            match ($sleep) {
                // Le mail arrive, mais sa pièce jointe pas encore.
                1 => $this->postMessage(self::payload('piece', ['alice@test.fr'], [['2', 3]], '2026-11-09T14:03:01Z')),
                4 => $this->postPart('piece', '2', 'abc'),
                default => null,
            };
        });

        $data = self::json($this->get('since=2026-11-09T14:03:00Z&wait=60'));

        self::assertSame(4000, $data['waited_ms']);
        self::assertSame(['piece'], array_column($data['messages'], 'id'));
        self::assertTrue($data['messages'][0]['complete']);
        self::assertSame('stored', $data['messages'][0]['parts'][0]['status']);
    }

    public function testIncompleteMessageIsLeftOutOfTheResultWithWaitOnly(): void
    {
        $this->postMessage(self::payload('piece', ['alice@test.fr'], [['2', 3]], '2026-11-09T14:03:01Z'));

        // Avec wait : ni attente satisfaite, ni mail incomplet dans la réponse (total compris).
        $data = self::json($this->get('since=2026-11-09T14:03:00Z&wait=2'));
        self::assertSame(['total' => 0, 'count' => 0], ['total' => $data['total'], 'count' => $data['count']]);
        self::assertSame(2000, $data['waited_ms']);

        // Sans wait : le mail est listé, avec « complete »: false, et sans « waited_ms ».
        $data = self::json($this->get('since=2026-11-09T14:03:00Z'));
        self::assertSame(['piece'], array_column($data['messages'], 'id'));
        self::assertFalse($data['messages'][0]['complete']);
        self::assertArrayNotHasKey('waited_ms', $data);
    }

    public function testWaitReturnsAtOnceWhenAMessageAlreadyMatches(): void
    {
        // Sans since : le mail déjà reçu suffit.
        $data = self::json($this->get('wait=60'));

        self::assertSame(0, $data['waited_ms']);
        self::assertSame([], $this->clock->sleeps);
        self::assertSame(['ancien'], array_column($data['messages'], 'id'));
    }

    public function testWaitStopsWhenTheClientIsGone(): void
    {
        $this->clock->clientGone = true;

        $data = self::json($this->get('since=2026-11-09T14:03:00Z&wait=60'));

        self::assertSame([], $this->clock->sleeps);
        self::assertSame(0, $data['waited_ms']);
        self::assertSame(0, $data['count']);
    }

    public function testRelayTokenCanWaitForAnyAddress(): void
    {
        $this->clock->onSleep(function (int $sleep): void {
            if ($sleep === 1) {
                $this->postMessage(self::payload('pour-bob', ['bob@test.fr'], created: '2026-11-09T14:03:01Z'));
            }
        });

        $data = self::json($this->get('address=bob@test.fr&since=2026-11-09T14:03:00Z&wait=5', self::RELAY_TOKEN, self::RELAY_IP));

        self::assertSame(1000, $data['waited_ms']);
        self::assertSame(['pour-bob'], array_column($data['messages'], 'id'));
    }

    public function testWaitKeepsTheTokenScope(): void
    {
        $response = $this->get('address=bob@test.fr&wait=5');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->clock->sleeps, 'refus avant toute attente');
    }

    public function testTokenWithoutAddressDoesNotWait(): void
    {
        $empty = new TokenRepository($this->database)->create('Vide')['token'];

        $data = self::json($this->get('wait=60', $empty));

        self::assertSame(0, $data['waited_ms']);
        self::assertSame(0, $data['count']);
        self::assertSame([], $this->clock->sleeps);
    }

    public function testSinceIsStrictlyAfterToTheMillisecond(): void
    {
        // Mailpit donne des dates à la nanoseconde ; l'API les affiche à la milliseconde.
        $this->postMessage(self::payload('a', ['alice@test.fr'], created: '2026-11-09T14:03:00.123456789Z'));
        $this->postMessage(self::payload('b', ['alice@test.fr'], created: '2026-11-09T14:03:00.124Z'));

        $data = self::json($this->get('limit=25&since=2026-11-09T14:03:00.122Z'));
        self::assertSame(['b', 'a'], array_column($data['messages'], 'id'));
        self::assertSame('2026-11-09T14:03:00.123Z', $data['messages'][1]['created_at']);

        // La date d'un mail telle que l'API l'affiche : ce mail n'est pas « après » lui-même.
        $data = self::json($this->get('since=' . rawurlencode('2026-11-09T14:03:00.123Z')));
        self::assertSame(['b'], array_column($data['messages'], 'id'));
        self::assertSame(1, $data['total']);

        // Même instant avec un fuseau ; un « + » non encodé (devenu une espace) est accepté.
        self::assertSame(['b'], array_column(self::json($this->get('since=' . rawurlencode('2026-11-09T15:03:00.123+01:00')))['messages'], 'id'));
        self::assertSame(['b'], array_column(self::json($this->get('since=2026-11-09T15:03:00.123+01:00'))['messages'], 'id'));

        self::assertSame(0, self::json($this->get('since=2026-11-09T14:03:00.124Z'))['total']);
        // Sans since : tous les mails d'alice, comme avant.
        self::assertSame(3, self::json($this->get(''))['total']);
    }

    public function testInvalidParameters(): void
    {
        $wait = '« wait » doit être un nombre de secondes entre 1 et 60';
        $since = '« since » doit être une date ISO 8601 avec fuseau, par exemple 2026-11-09T14:03:00Z';
        $cases = [
            'wait=0'                            => $wait,
            'wait=61'                           => $wait,
            'wait=-1'                           => $wait,
            'wait=1.5'                          => $wait,
            'wait=abc'                          => $wait,
            'wait[]=5'                          => $wait,
            'since=hier'                        => $since,
            'since=2026-11-09'                  => $since,
            'since=2026-11-09T14:03:00'         => $since,
            'since=2026-02-30T10:00:00Z'        => $since,
            'since=2026-11-09T24:00:00Z'        => $since,
            'since=1762697000'                  => $since,
            'since[]=2026-11-09T14:03:00Z'      => $since,
        ];
        foreach ($cases as $query => $error) {
            $response = $this->get($query);
            self::assertSame(400, $response->getStatusCode(), $query);
            self::assertSame(['error' => $error], self::json($response), $query);
        }
        self::assertSame([], $this->clock->sleeps);

        // Vides : comme absents.
        $data = self::json($this->get('wait=&since='));
        self::assertSame(1, $data['total']);
        self::assertArrayNotHasKey('waited_ms', $data);
    }

    public function testRealClockWaitsForTheDelay(): void
    {
        $this->waitClock = new SystemWaitClock();
        $this->useConfig($this->config());
        $start = hrtime(true);

        $data = self::json($this->get('since=2026-11-09T14:03:00Z&wait=1'));

        $elapsed = (hrtime(true) - $start) / 1e6;
        self::assertSame(0, $data['count']);
        self::assertGreaterThanOrEqual(1000, $data['waited_ms']);
        self::assertLessThan(1500, $data['waited_ms']);
        self::assertGreaterThanOrEqual(1000, $elapsed);
    }
}
