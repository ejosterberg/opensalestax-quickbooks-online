<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Oauth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenSalesTax\QuickBooksOnline\Oauth\OAuthException;
use OpenSalesTax\QuickBooksOnline\Oauth\OAuthFlow;
use OpenSalesTax\QuickBooksOnline\Oauth\TokenSet;
use PHPUnit\Framework\TestCase;

final class OAuthFlowTest extends TestCase
{
    private function flow(MockHandler $mock, int $now = 1_700_000_000): OAuthFlow
    {
        $client = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
        return new OAuthFlow(
            clientId: 'CID',
            clientSecret: 'SECRET',
            redirectUri: 'https://sidecar.example.com/oauth/callback',
            http: $client,
            clock: static fn () => $now,
        );
    }

    public function testAuthorizationUrlIncludesParams(): void
    {
        $url = $this->flow(new MockHandler())->authorizationUrl('state-XYZ');
        $this->assertStringStartsWith(OAuthFlow::AUTH_URL . '?', $url);
        $this->assertStringContainsString('client_id=CID', $url);
        $this->assertStringContainsString('state=state-XYZ', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('scope=com.intuit.quickbooks.accounting', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fsidecar.example.com%2Foauth%2Fcallback', $url);
    }

    public function testExchangeCodeBuildsTokenSet(): void
    {
        $body = json_encode([
            'access_token' => 'access-A',
            'refresh_token' => 'refresh-R',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8_640_000,
        ]);
        $this->assertIsString($body);
        $mock = new MockHandler([new Response(200, [], $body)]);
        $flow = $this->flow($mock, 1_700_000_000);
        $tokens = $flow->exchangeCode('the-code', '999');
        $this->assertInstanceOf(TokenSet::class, $tokens);
        $this->assertSame('access-A', $tokens->accessToken);
        $this->assertSame('refresh-R', $tokens->refreshToken);
        $this->assertSame('999', $tokens->realmId);
        $this->assertSame(1_700_003_600, $tokens->accessTokenExpiresAt);
        $this->assertSame(1_708_640_000, $tokens->refreshTokenExpiresAt);
    }

    public function testRefreshBuildsTokenSet(): void
    {
        $body = json_encode([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8_640_000,
        ]);
        $this->assertIsString($body);
        $mock = new MockHandler([new Response(200, [], $body)]);
        $flow = $this->flow($mock);
        $current = new TokenSet('999', 'old', 0, 'old-r', 0);
        $next = $flow->refresh($current);
        $this->assertSame('new-access', $next->accessToken);
        $this->assertSame('new-refresh', $next->refreshToken);
    }

    public function testNon200RejectedAsOAuthException(): void
    {
        $mock = new MockHandler([new Response(400, [], '{"error":"invalid_grant"}')]);
        $flow = $this->flow($mock);
        $this->expectException(OAuthException::class);
        $flow->exchangeCode('bad-code', '999');
    }

    public function testMalformedTokenResponseRejected(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"access_token":"a"}')]);
        $flow = $this->flow($mock);
        $this->expectException(OAuthException::class);
        $flow->exchangeCode('the-code', '999');
    }
}
