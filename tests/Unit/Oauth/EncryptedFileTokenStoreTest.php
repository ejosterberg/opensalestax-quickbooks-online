<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Oauth;

use OpenSalesTax\QuickBooksOnline\Oauth\EncryptedFileTokenStore;
use OpenSalesTax\QuickBooksOnline\Oauth\TokenSet;
use OpenSalesTax\QuickBooksOnline\Oauth\TokenStoreException;
use PHPUnit\Framework\TestCase;

final class EncryptedFileTokenStoreTest extends TestCase
{
    private string $path = '';
    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $tmpDir = sys_get_temp_dir() . '/ostax-qbo-store-' . bin2hex(random_bytes(4));
        @mkdir($tmpDir, 0o700, true);
        $this->path = $tmpDir . '/tokens.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    public function testRoundTripStoreAndLoad(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $tokens = new TokenSet(
            realmId: '999',
            accessToken: 'access-XYZ',
            accessTokenExpiresAt: 1_700_003_600,
            refreshToken: 'refresh-ABC',
            refreshTokenExpiresAt: 1_708_000_000,
        );
        $store->save($tokens);
        $loaded = $store->load('999');
        $this->assertNotNull($loaded);
        $this->assertSame('access-XYZ', $loaded->accessToken);
        $this->assertSame('refresh-ABC', $loaded->refreshToken);
        $this->assertSame(1_700_003_600, $loaded->accessTokenExpiresAt);
    }

    public function testCiphertextDoesNotContainPlaintext(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->save(new TokenSet('999', 'PLAINTEXT-MARKER', 0, 'REFRESH-MARKER', 0));
        $raw = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('PLAINTEXT-MARKER', $raw);
        $this->assertStringNotContainsString('REFRESH-MARKER', $raw);
    }

    public function testWrongKeyFailsDecryption(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->save(new TokenSet('999', 'access', 0, 'refresh', 0));
        $other = new EncryptedFileTokenStore($this->path, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->expectException(TokenStoreException::class);
        $other->load('999');
    }

    public function testLoadMissingRealmReturnsNull(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $this->assertNull($store->load('999'));
    }

    public function testMultipleRealmsCoexist(): void
    {
        $store = new EncryptedFileTokenStore($this->path, $this->key);
        $store->save(new TokenSet('111', 'a1', 0, 'r1', 0));
        $store->save(new TokenSet('222', 'a2', 0, 'r2', 0));
        $this->assertSame('a1', $store->load('111')?->accessToken);
        $this->assertSame('a2', $store->load('222')?->accessToken);
        $realms = $store->listRealms();
        sort($realms);
        $this->assertSame(['111', '222'], $realms);
    }

    public function testWrongKeySizeRejected(): void
    {
        $this->expectException(TokenStoreException::class);
        new EncryptedFileTokenStore($this->path, 'too-short');
    }
}
