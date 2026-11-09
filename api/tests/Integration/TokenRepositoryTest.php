<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Persistence\TokenRepository;
use App\Tests\Support\DatabaseTestCase;

final class TokenRepositoryTest extends DatabaseTestCase
{
    private TokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenRepository($this->database);
    }

    public function testCreateStoresOnlyTheHash(): void
    {
        $created = $this->tokens->create('Projet A');

        self::assertMatchesRegularExpression('/^mpt_[0-9a-f]{48}$/', $created['token']);
        $row = $this->pdo->query('SELECT * FROM mailpit_tokens')->fetch();
        self::assertSame($created['id'], (int) $row['id']);
        self::assertSame('Projet A', $row['name']);
        self::assertSame(hash('sha256', $created['token']), $row['token_hash']);
        self::assertSame(substr($created['token'], 0, 12), $row['token_hint']);
        self::assertNull($row['last_used_at']);
    }

    public function testAuthenticate(): void
    {
        $created = $this->tokens->create('Projet A');

        self::assertSame(['id' => $created['id'], 'name' => 'Projet A'], $this->tokens->authenticate($created['token']));
        self::assertNotNull($this->scalar('SELECT last_used_at FROM mailpit_tokens'));

        self::assertNull($this->tokens->authenticate($created['token'] . 'x'));
        self::assertNull($this->tokens->authenticate('mpt_inconnu'));
        self::assertNull($this->tokens->authenticate(substr($created['token'], 4)), 'sans le préfixe mpt_');
        self::assertNull($this->tokens->authenticate('mpt_' . str_repeat('a', 200)), 'trop long');
    }

    public function testLastUsedIsUpdatedAtMostOncePerMinute(): void
    {
        $created = $this->tokens->create('Projet A');
        $this->pdo->exec("UPDATE mailpit_tokens SET last_used_at = '2026-01-01 00:00:00'");

        $this->tokens->authenticate($created['token']);
        $first = $this->scalar('SELECT last_used_at FROM mailpit_tokens');
        self::assertNotSame('2026-01-01 00:00:00.000000', $first);

        $this->tokens->authenticate($created['token']);
        self::assertSame($first, $this->scalar('SELECT last_used_at FROM mailpit_tokens'));
    }

    public function testFindAllAndAddresses(): void
    {
        $a = $this->tokens->create('Projet A');
        $b = $this->tokens->create(str_repeat('é', 300));

        self::assertTrue($this->tokens->addAddress($a['id'], 'bob@test.fr'));
        self::assertTrue($this->tokens->addAddress($a['id'], 'alice@test.fr'));
        self::assertFalse($this->tokens->addAddress($a['id'], 'alice@test.fr'), 'déjà associée');
        self::assertTrue($this->tokens->addAddress($b['id'], 'alice@test.fr'), 'une adresse peut être sur plusieurs jetons');

        self::assertSame(['alice@test.fr', 'bob@test.fr'], $this->tokens->addresses($a['id']));

        $all = $this->tokens->all();
        self::assertSame([$b['id'], $a['id']], array_column($all, 'id'), 'du plus récent au plus ancien');
        self::assertSame([1, 2], array_column($all, 'addresses'));
        self::assertSame(str_repeat('é', 255), $all[0]['name'], 'nom coupé à 255 caractères');

        $found = $this->tokens->find($a['id']);
        self::assertSame('Projet A', $found['name'] ?? null);
        self::assertSame(substr($a['token'], 0, 12), $found['hint'] ?? null);
        self::assertInstanceOf(\DateTimeImmutable::class, $found['created_at'] ?? null);
        self::assertNotNull($found);
        self::assertNull($found['last_used_at']);
        self::assertNull($this->tokens->find(999));

        self::assertTrue($this->tokens->removeAddress($a['id'], 'bob@test.fr'));
        self::assertFalse($this->tokens->removeAddress($a['id'], 'bob@test.fr'));
        self::assertSame(['alice@test.fr'], $this->tokens->addresses($a['id']));
    }

    public function testDeleteRemovesAddresses(): void
    {
        $a = $this->tokens->create('Projet A');
        $this->tokens->addAddress($a['id'], 'alice@test.fr');

        self::assertTrue($this->tokens->delete($a['id']));
        self::assertFalse($this->tokens->delete($a['id']));
        self::assertNull($this->tokens->authenticate($a['token']));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM mailpit_token_addresses'));
    }
}
