<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Security;

use OpenSalesTax\QuickBooksOnline\Security\UrlValidator;
use PHPUnit\Framework\TestCase;

final class UrlValidatorTest extends TestCase
{
    public function testAllowsHttpsPublic(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: false, hostResolver: static fn () => '8.8.8.8');
        $this->assertSame('8.8.8.8', $v->validate('https://example.com/foo'));
    }

    public function testRejectsPrivateIpWhenForbidden(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: false, hostResolver: static fn () => '10.0.0.5');
        $this->expectException(\InvalidArgumentException::class);
        $v->validate('https://internal.example.com');
    }

    public function testAllowsPrivateIpWhenPermitted(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        $this->assertNull($v->validate('http://10.0.0.5:8080'));
    }

    public function testRejectsNonHttpScheme(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        $this->expectException(\InvalidArgumentException::class);
        $v->validate('file:///etc/passwd');
    }

    public function testRejectsMalformedUrl(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        $this->expectException(\InvalidArgumentException::class);
        $v->validate('not-a-url');
    }

    public function testRejectsEmptyUrl(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: true);
        $this->expectException(\InvalidArgumentException::class);
        $v->validate('');
    }

    public function testRejectsUnresolvableHost(): void
    {
        $v = new UrlValidator(allowPrivateNetworks: false, hostResolver: static fn () => null);
        $this->expectException(\InvalidArgumentException::class);
        $v->validate('https://does-not-exist.example.invalid');
    }
}
