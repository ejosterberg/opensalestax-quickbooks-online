<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Security;

use OpenSalesTax\QuickBooksOnline\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testAllowsUpToCapacity(): void
    {
        $limiter = new RateLimiter(3, clock: static fn () => 1.0);
        $this->assertTrue($limiter->allow('1.2.3.4'));
        $this->assertTrue($limiter->allow('1.2.3.4'));
        $this->assertTrue($limiter->allow('1.2.3.4'));
        $this->assertFalse($limiter->allow('1.2.3.4'));
    }

    public function testIndependentBucketsPerIp(): void
    {
        $limiter = new RateLimiter(1, clock: static fn () => 1.0);
        $this->assertTrue($limiter->allow('1.1.1.1'));
        $this->assertTrue($limiter->allow('2.2.2.2'));
        $this->assertFalse($limiter->allow('1.1.1.1'));
        $this->assertFalse($limiter->allow('2.2.2.2'));
    }

    public function testRefillAfterTime(): void
    {
        $now = 1.0;
        $limiter = new RateLimiter(60, clock: function () use (&$now): float {
            return $now;
        });
        for ($i = 0; $i < 60; $i++) {
            $limiter->allow('ip');
        }
        $this->assertFalse($limiter->allow('ip'));
        // Advance 30 seconds → refills 30 tokens at 1/sec.
        $now += 30.0;
        $this->assertTrue($limiter->allow('ip'));
    }
}
