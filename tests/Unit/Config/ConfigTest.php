<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Config;

use OpenSalesTax\QuickBooksOnline\Config\Config;
use OpenSalesTax\QuickBooksOnline\Config\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function valid(): array
    {
        return [
            'OST_ENGINE_URL' => 'https://ost.example.com',
            'QBO_CLIENT_ID' => 'client-id',
            'QBO_CLIENT_SECRET' => 'super-secret',
            'QBO_REDIRECT_URI' => 'https://sidecar.example.com/oauth/callback',
            'QBO_ENVIRONMENT' => 'sandbox',
            'QBO_WEBHOOK_VERIFIER_TOKEN' => 'verifier-token-32+chars-XXXXXXXX',
            'QBO_TOKEN_ENCRYPTION_KEY' => base64_encode(str_repeat("\x01", 32)),
        ];
    }

    public function testValidConfigReturnsExpectedValues(): void
    {
        $config = new Config(self::valid());
        $this->assertSame('https://ost.example.com', $config->engineUrl());
        $this->assertSame('client-id', $config->qboClientId());
        $this->assertSame('super-secret', $config->qboClientSecret());
        $this->assertSame('sandbox', $config->qboEnvironment());
        $this->assertSame(10.0, $config->engineTimeoutSeconds());
        $this->assertTrue($config->allowPrivateNetworks());
        $this->assertTrue($config->tlsVerify());
        $this->assertSame(120, $config->rateLimitPerMinute());
        $this->assertSame(300, $config->replayWindowSeconds());
        $this->assertFalse($config->failHard());
        $this->assertSame('./var/qbo-tokens.json', $config->qboTokenStorePath());
        $this->assertSame(str_repeat("\x01", 32), $config->qboTokenEncryptionKey());
    }

    public function testMissingRequiredVarThrows(): void
    {
        $env = self::valid();
        unset($env['QBO_CLIENT_ID']);
        $this->expectException(ConfigException::class);
        (new Config($env))->qboClientId();
    }

    public function testEnvironmentRejectsInvalidValue(): void
    {
        $env = self::valid();
        $env['QBO_ENVIRONMENT'] = 'staging';
        $this->expectException(ConfigException::class);
        (new Config($env))->qboEnvironment();
    }

    public function testEncryptionKeyMustDecodeToThirtyTwoBytes(): void
    {
        $env = self::valid();
        $env['QBO_TOKEN_ENCRYPTION_KEY'] = base64_encode('too-short');
        $this->expectException(ConfigException::class);
        (new Config($env))->qboTokenEncryptionKey();
    }

    public function testEncryptionKeyMustBeBase64(): void
    {
        $env = self::valid();
        $env['QBO_TOKEN_ENCRYPTION_KEY'] = '!!!not-base64!!!';
        $this->expectException(ConfigException::class);
        (new Config($env))->qboTokenEncryptionKey();
    }

    public function testDebugInfoRedactsSecrets(): void
    {
        $config = new Config(self::valid());
        $dump = $config->__debugInfo();
        $this->assertSame('***REDACTED***', $dump['QBO_CLIENT_SECRET']);
        $this->assertSame('***REDACTED***', $dump['QBO_WEBHOOK_VERIFIER_TOKEN']);
        $this->assertSame('***REDACTED***', $dump['QBO_TOKEN_ENCRYPTION_KEY']);
    }

    public function testFailHardFlagParses(): void
    {
        $env = self::valid();
        $env['OSTAX_FAIL_HARD'] = '1';
        $this->assertTrue((new Config($env))->failHard());
    }

    public function testTimeoutOutOfRangeRejected(): void
    {
        $env = self::valid();
        $env['OST_TIMEOUT_SECONDS'] = '600';
        $this->expectException(ConfigException::class);
        (new Config($env))->engineTimeoutSeconds();
    }
}
