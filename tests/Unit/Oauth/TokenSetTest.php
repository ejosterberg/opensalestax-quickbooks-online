<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Oauth;

use OpenSalesTax\QuickBooksOnline\Oauth\TokenSet;
use PHPUnit\Framework\TestCase;

final class TokenSetTest extends TestCase
{
    public function testIsAccessTokenExpiredHonoursSkew(): void
    {
        $now = 1_700_000_000;
        $set = new TokenSet('999', 'a', $now + 30, 'r', $now + 10_000);
        $this->assertTrue($set->isAccessTokenExpired(60, $now));
        $this->assertFalse($set->isAccessTokenExpired(0, $now));
    }

    public function testRoundTripArray(): void
    {
        $set = new TokenSet('999', 'a', 100, 'r', 200);
        $copy = TokenSet::fromArray($set->toArray());
        $this->assertSame($set->realmId, $copy->realmId);
        $this->assertSame($set->accessToken, $copy->accessToken);
        $this->assertSame($set->accessTokenExpiresAt, $copy->accessTokenExpiresAt);
    }

    public function testFromArrayMissingFieldsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenSet::fromArray(['realm_id' => '999']);
    }
}
