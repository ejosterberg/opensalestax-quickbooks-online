<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Oauth;

/**
 * Immutable bundle of an Intuit OAuth grant: the realm (company) id,
 * the access token, the refresh token, and their respective expiry
 * timestamps.
 *
 * Realm id is what Intuit calls the QBO company id; every QBO API call
 * is scoped to a single realm.
 */
final class TokenSet
{
    public function __construct(
        public readonly string $realmId,
        public readonly string $accessToken,
        public readonly int $accessTokenExpiresAt,
        public readonly string $refreshToken,
        public readonly int $refreshTokenExpiresAt,
    ) {
    }

    public function isAccessTokenExpired(int $skewSeconds = 60, ?int $now = null): bool
    {
        $now ??= time();
        return $now + $skewSeconds >= $this->accessTokenExpiresAt;
    }

    /**
     * @return array{
     *   realm_id: string,
     *   access_token: string,
     *   access_token_expires_at: int,
     *   refresh_token: string,
     *   refresh_token_expires_at: int
     * }
     */
    public function toArray(): array
    {
        return [
            'realm_id' => $this->realmId,
            'access_token' => $this->accessToken,
            'access_token_expires_at' => $this->accessTokenExpiresAt,
            'refresh_token' => $this->refreshToken,
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $realm = $data['realm_id'] ?? null;
        $access = $data['access_token'] ?? null;
        $accessExpiry = $data['access_token_expires_at'] ?? null;
        $refresh = $data['refresh_token'] ?? null;
        $refreshExpiry = $data['refresh_token_expires_at'] ?? null;
        if (
            !is_string($realm) || !is_string($access) || !is_int($accessExpiry)
            || !is_string($refresh) || !is_int($refreshExpiry)
        ) {
            throw new \InvalidArgumentException('TokenSet array missing required typed fields');
        }
        return new self($realm, $access, $accessExpiry, $refresh, $refreshExpiry);
    }
}
