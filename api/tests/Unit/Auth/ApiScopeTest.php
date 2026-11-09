<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\ApiScope;
use PHPUnit\Framework\TestCase;

final class ApiScopeTest extends TestCase
{
    public function testDeveloperToken(): void
    {
        $scope = ApiScope::token(7, ['alice@test.fr', 'bob@test.fr']);

        self::assertFalse($scope->isRelay());
        self::assertSame(7, $scope->tokenId);
        self::assertSame(['alice@test.fr', 'bob@test.fr'], $scope->addresses);
        self::assertTrue($scope->allows('alice@test.fr'));
        self::assertFalse($scope->allows('carol@test.fr'));
        // Les adresses sont comparées telles quelles : l'appelant les normalise avant.
        self::assertFalse($scope->allows('Alice@test.fr'));
    }

    public function testTokenWithoutAddressesAllowsNothing(): void
    {
        $scope = ApiScope::token(1, []);

        self::assertSame([], $scope->addresses);
        self::assertFalse($scope->allows('alice@test.fr'));
    }

    public function testRelayAllowsEverything(): void
    {
        $scope = ApiScope::relay();

        self::assertTrue($scope->isRelay());
        self::assertNull($scope->tokenId);
        self::assertNull($scope->addresses);
        self::assertTrue($scope->allows('nimporte@qui.fr'));
    }
}
