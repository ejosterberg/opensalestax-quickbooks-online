<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenSalesTax\QuickBooksOnline\Http\Request;
use OpenSalesTax\QuickBooksOnline\Oauth\EncryptedFileTokenStore;
use OpenSalesTax\QuickBooksOnline\Oauth\OAuthFlow;
use OpenSalesTax\QuickBooksOnline\Service\OAuthCallbackHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OAuthCallbackHandlerTest extends TestCase
{
    private string $statePath = '';
    private string $tokensPath = '';

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/ostax-qbo-cb-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0o700, true);
        $this->statePath = $tmp . '/oauth-state.txt';
        $this->tokensPath = $tmp . '/tokens.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->statePath, $this->tokensPath] as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
        @rmdir(dirname($this->statePath));
    }

    private function handler(MockHandler $http): OAuthCallbackHandler
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $store = new EncryptedFileTokenStore($this->tokensPath, $key);
        $oauth = new OAuthFlow(
            clientId: 'CID',
            clientSecret: 'SECRET',
            redirectUri: 'http://localhost/oauth/callback',
            http: new GuzzleClient(['handler' => HandlerStack::create($http)]),
            clock: static fn () => 1_700_000_000,
        );
        return new OAuthCallbackHandler(
            oauth: $oauth,
            tokenStore: $store,
            stateFilePath: $this->statePath,
            logger: new NullLogger(),
        );
    }

    public function testCallbackPersistsTokensOnStateMatch(): void
    {
        $body = (string) json_encode([
            'access_token' => 'access-X',
            'refresh_token' => 'refresh-Y',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8_640_000,
        ]);
        $handler = $this->handler(new MockHandler([new GuzzleResponse(200, [], $body)]));
        $handler->writeState('STATE-1');
        $resp = $handler->handle(new Request(
            method: 'GET',
            path: '/oauth/callback',
            headers: [],
            body: '',
            sourceIp: '127.0.0.1',
            query: ['code' => 'abc', 'realmId' => '999', 'state' => 'STATE-1'],
        ));
        $this->assertSame(200, $resp->status);
        $this->assertFileDoesNotExist($this->statePath, 'state file should be cleared');
        $this->assertFileExists($this->tokensPath);
    }

    public function testStateMismatchRejected(): void
    {
        $handler = $this->handler(new MockHandler());
        $handler->writeState('STATE-1');
        $resp = $handler->handle(new Request(
            method: 'GET',
            path: '/oauth/callback',
            headers: [],
            body: '',
            sourceIp: '127.0.0.1',
            query: ['code' => 'abc', 'realmId' => '999', 'state' => 'WRONG'],
        ));
        $this->assertSame(400, $resp->status);
        $this->assertFileExists($this->statePath, 'state file should remain on rejection');
    }

    public function testMissingQueryParamsReturns400(): void
    {
        $handler = $this->handler(new MockHandler());
        $resp = $handler->handle(new Request(
            method: 'GET',
            path: '/oauth/callback',
            headers: [],
            body: '',
            sourceIp: '127.0.0.1',
            query: ['code' => 'abc'],
        ));
        $this->assertSame(400, $resp->status);
    }

    public function testNonCallbackPathReturns404(): void
    {
        $handler = $this->handler(new MockHandler());
        $resp = $handler->handle(new Request('GET', '/health', [], '', '127.0.0.1'));
        $this->assertSame(404, $resp->status);
    }
}
