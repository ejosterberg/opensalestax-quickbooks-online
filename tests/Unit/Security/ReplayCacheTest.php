<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Security;

use OpenSalesTax\QuickBooksOnline\Security\ReplayCache;
use PHPUnit\Framework\TestCase;

final class ReplayCacheTest extends TestCase
{
    public function testFirstSeenAllowed(): void
    {
        $cache = new ReplayCache(ttlSeconds: 60);
        $this->assertTrue($cache->checkAndRemember('payload-A'));
    }

    public function testDuplicateRejectedWithinWindow(): void
    {
        $now = 1_700_000_000;
        $cache = new ReplayCache(ttlSeconds: 60, clock: static fn () => $now);
        $cache->checkAndRemember('payload-A');
        $this->assertFalse($cache->checkAndRemember('payload-A'));
    }

    public function testExpiryAllowsRepeat(): void
    {
        $now = 1_700_000_000;
        $cache = new ReplayCache(ttlSeconds: 60, clock: function () use (&$now): int {
            return $now;
        });
        $cache->checkAndRemember('payload-A');
        $now += 120;
        $this->assertTrue($cache->checkAndRemember('payload-A'));
    }

    public function testMaxEntriesEnforced(): void
    {
        $cache = new ReplayCache(ttlSeconds: 60, maxEntries: 3);
        $cache->checkAndRemember('a');
        $cache->checkAndRemember('b');
        $cache->checkAndRemember('c');
        $cache->checkAndRemember('d');
        // 'a' should have been evicted; resubmitting it should be allowed.
        $this->assertTrue($cache->checkAndRemember('a'));
    }
}
