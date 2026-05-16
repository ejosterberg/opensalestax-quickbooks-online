<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Oauth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;

/**
 * OAuth 2.0 authorization-code flow against Intuit's QuickBooks Online API.
 *
 * Talks directly to Intuit's `tokens/bearer` endpoint with
 * `client_credentials`-style HTTP Basic auth (the client_id+client_secret
 * pair) — same handshake `quickbooks/v3-php-sdk`'s OAuth2LoginHelper uses
 * under the hood, but kept here as a thin Guzzle call so unit tests can
 * mock the exchange without booting the full Intuit SDK session machinery.
 *
 * URLs (constants below) are the canonical Intuit endpoints; they don't
 * differ between sandbox and production for OAuth (only the API base URL
 * does).
 *
 * The class deliberately doesn't manage the merchant's `state` token —
 * that's the caller's responsibility (Bootstrap stashes it in a tiny
 * file before redirecting and verifies on the callback).
 */
final class OAuthFlow
{
    public const AUTH_URL = 'https://appcenter.intuit.com/connect/oauth2';
    public const TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
    public const SCOPE_ACCOUNTING = 'com.intuit.quickbooks.accounting';

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly GuzzleClient $http,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Build the URL the merchant's browser must visit to authorize the app.
     *
     * @param string $state CSRF token the caller generated and remembers.
     */
    public function authorizationUrl(string $state, string $scope = self::SCOPE_ACCOUNTING): string
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'scope' => $scope,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code (from the OAuth callback) for an
     * access + refresh token pair.
     *
     * @throws OAuthException on any HTTP / parse failure.
     */
    public function exchangeCode(string $code, string $realmId): TokenSet
    {
        $body = $this->postToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);
        return $this->buildTokenSet($body, $realmId);
    }

    /**
     * Use a refresh token to mint a new access+refresh token pair. The old
     * refresh token is invalidated by Intuit at this point.
     */
    public function refresh(TokenSet $current): TokenSet
    {
        $body = $this->postToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $current->refreshToken,
        ]);
        return $this->buildTokenSet($body, $current->realmId);
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function postToken(array $form): array
    {
        try {
            $response = $this->http->post(self::TOKEN_URL, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'auth' => [$this->clientId, $this->clientSecret],
                'form_params' => $form,
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 10,
            ]);
        } catch (GuzzleException $e) {
            throw new OAuthException('Intuit token endpoint transport error: ' . $e->getMessage());
        }
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new OAuthException("Intuit token endpoint returned HTTP {$status}: {$raw}");
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OAuthException('Intuit token endpoint returned non-JSON body: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new OAuthException('Intuit token endpoint returned non-object JSON');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildTokenSet(array $body, string $realmId): TokenSet
    {
        $access = $body['access_token'] ?? null;
        $accessTtl = $body['expires_in'] ?? null;
        $refresh = $body['refresh_token'] ?? null;
        $refreshTtl = $body['x_refresh_token_expires_in'] ?? null;
        if (!is_string($access) || !is_string($refresh) || !is_int($accessTtl) || !is_int($refreshTtl)) {
            throw new OAuthException(
                'Intuit token response missing access_token / refresh_token / TTL fields'
            );
        }
        $now = ($this->clock)();
        return new TokenSet(
            realmId: $realmId,
            accessToken: $access,
            accessTokenExpiresAt: $now + $accessTtl,
            refreshToken: $refresh,
            refreshTokenExpiresAt: $now + $refreshTtl,
        );
    }
}
