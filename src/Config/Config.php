<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Config;

/**
 * Immutable runtime configuration for the QBO sidecar.
 *
 * Loaded from environment variables exactly once at boot. Secrets
 * (engine API key, OAuth client secret, webhook verifier token,
 * token-encryption key) are never logged and never serialized via
 * __toString().
 *
 * Required env vars:
 * - OST_ENGINE_URL                  base URL of the OpenSalesTax engine
 * - QBO_CLIENT_ID                   Intuit OAuth client ID
 * - QBO_CLIENT_SECRET               Intuit OAuth client secret
 * - QBO_REDIRECT_URI                OAuth redirect URI registered with Intuit
 * - QBO_ENVIRONMENT                 'sandbox' or 'production'
 * - QBO_WEBHOOK_VERIFIER_TOKEN      Intuit webhook verifier token
 * - QBO_TOKEN_ENCRYPTION_KEY        base64-encoded 32-byte key
 *
 * Optional:
 * - OST_API_KEY                     Bearer token for the OST engine
 * - OST_TIMEOUT_SECONDS             default 10
 * - QBO_TOKEN_STORE_PATH            default ./var/qbo-tokens.json
 * - SIDECAR_ALLOW_PRIVATE_NETWORKS  default 1
 * - SIDECAR_REPLAY_WINDOW_SECONDS   default 300
 * - SIDECAR_TLS_VERIFY              default 1
 * - SIDECAR_RATE_LIMIT_PER_MINUTE   default 120
 * - OSTAX_FAIL_HARD                 default 0
 */
final class Config
{
    /** @var array<string, string> */
    private array $env;

    /**
     * @param array<string, string>|null $env defaults to the process environment.
     */
    public function __construct(?array $env = null)
    {
        $this->env = $env ?? self::loadFromProcess();
    }

    public function engineUrl(): string
    {
        return $this->required('OST_ENGINE_URL');
    }

    public function engineApiKey(): ?string
    {
        $v = $this->optional('OST_API_KEY');
        return ($v === null || $v === '') ? null : $v;
    }

    public function engineTimeoutSeconds(): float
    {
        $v = $this->optional('OST_TIMEOUT_SECONDS');
        if ($v === null || $v === '') {
            return 10.0;
        }
        if (!is_numeric($v)) {
            throw new ConfigException('OST_TIMEOUT_SECONDS must be numeric');
        }
        $f = (float) $v;
        if ($f <= 0.0 || $f > 60.0) {
            throw new ConfigException('OST_TIMEOUT_SECONDS must be in (0, 60]');
        }
        return $f;
    }

    public function qboClientId(): string
    {
        return $this->required('QBO_CLIENT_ID');
    }

    public function qboClientSecret(): string
    {
        return $this->required('QBO_CLIENT_SECRET');
    }

    public function qboRedirectUri(): string
    {
        return $this->required('QBO_REDIRECT_URI');
    }

    /**
     * @return 'sandbox'|'production'
     */
    public function qboEnvironment(): string
    {
        $v = strtolower($this->required('QBO_ENVIRONMENT'));
        if ($v !== 'sandbox' && $v !== 'production') {
            throw new ConfigException("QBO_ENVIRONMENT must be 'sandbox' or 'production'");
        }
        return $v;
    }

    public function qboWebhookVerifierToken(): string
    {
        $v = $this->required('QBO_WEBHOOK_VERIFIER_TOKEN');
        if (strlen($v) < 8) {
            throw new ConfigException('QBO_WEBHOOK_VERIFIER_TOKEN looks too short to be valid');
        }
        return $v;
    }

    public function qboTokenStorePath(): string
    {
        $v = $this->optional('QBO_TOKEN_STORE_PATH');
        return ($v === null || $v === '') ? './var/qbo-tokens.json' : $v;
    }

    /**
     * Returns the raw 32 bytes of the token-encryption key (after base64
     * decoding the env var value).
     */
    public function qboTokenEncryptionKey(): string
    {
        $v = $this->required('QBO_TOKEN_ENCRYPTION_KEY');
        $raw = base64_decode($v, true);
        if ($raw === false) {
            throw new ConfigException('QBO_TOKEN_ENCRYPTION_KEY is not valid base64');
        }
        if (strlen($raw) !== 32) {
            throw new ConfigException(
                'QBO_TOKEN_ENCRYPTION_KEY must be base64 of exactly 32 bytes (got ' .
                strlen($raw) . ')'
            );
        }
        return $raw;
    }

    public function allowPrivateNetworks(): bool
    {
        $v = $this->optional('SIDECAR_ALLOW_PRIVATE_NETWORKS');
        return $v === null || $v === '' || $v === '1' || strtolower($v) === 'true';
    }

    public function replayWindowSeconds(): int
    {
        $v = $this->optional('SIDECAR_REPLAY_WINDOW_SECONDS');
        if ($v === null || $v === '') {
            return 300;
        }
        if (!ctype_digit($v)) {
            throw new ConfigException('SIDECAR_REPLAY_WINDOW_SECONDS must be a non-negative integer');
        }
        $i = (int) $v;
        if ($i < 30 || $i > 3600) {
            throw new ConfigException('SIDECAR_REPLAY_WINDOW_SECONDS must be in [30, 3600]');
        }
        return $i;
    }

    public function tlsVerify(): bool
    {
        $v = $this->optional('SIDECAR_TLS_VERIFY');
        if ($v === null || $v === '') {
            return true;
        }
        return !($v === '0' || strtolower($v) === 'false');
    }

    public function rateLimitPerMinute(): int
    {
        $v = $this->optional('SIDECAR_RATE_LIMIT_PER_MINUTE');
        if ($v === null || $v === '') {
            return 120;
        }
        if (!ctype_digit($v)) {
            throw new ConfigException('SIDECAR_RATE_LIMIT_PER_MINUTE must be a non-negative integer');
        }
        $i = (int) $v;
        if ($i < 1 || $i > 10000) {
            throw new ConfigException('SIDECAR_RATE_LIMIT_PER_MINUTE must be in [1, 10000]');
        }
        return $i;
    }

    public function failHard(): bool
    {
        $v = $this->optional('OSTAX_FAIL_HARD');
        if ($v === null || $v === '') {
            return false;
        }
        return $v === '1' || strtolower($v) === 'true';
    }

    private function required(string $key): string
    {
        $v = $this->env[$key] ?? '';
        if ($v === '') {
            throw new ConfigException(sprintf('Required env var %s is not set', $key));
        }
        return $v;
    }

    private function optional(string $key): ?string
    {
        return $this->env[$key] ?? null;
    }

    /** @return array<string, string> */
    private static function loadFromProcess(): array
    {
        $out = [];
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        $keys = [
            'OST_ENGINE_URL', 'OST_API_KEY', 'OST_TIMEOUT_SECONDS',
            'QBO_CLIENT_ID', 'QBO_CLIENT_SECRET', 'QBO_REDIRECT_URI',
            'QBO_ENVIRONMENT', 'QBO_WEBHOOK_VERIFIER_TOKEN',
            'QBO_TOKEN_STORE_PATH', 'QBO_TOKEN_ENCRYPTION_KEY',
            'SIDECAR_ALLOW_PRIVATE_NETWORKS', 'SIDECAR_REPLAY_WINDOW_SECONDS',
            'SIDECAR_TLS_VERIFY', 'SIDECAR_RATE_LIMIT_PER_MINUTE',
            'OSTAX_FAIL_HARD',
        ];
        foreach ($keys as $k) {
            if (!isset($out[$k])) {
                $v = getenv($k);
                if (is_string($v) && $v !== '') {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    /**
     * Suppress accidental var_dump / print_r exposure of secret keys.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        $masked = $this->env;
        foreach (
            [
            'OST_API_KEY',
            'QBO_CLIENT_SECRET',
            'QBO_WEBHOOK_VERIFIER_TOKEN',
            'QBO_TOKEN_ENCRYPTION_KEY',
            ] as $k
        ) {
            if (isset($masked[$k])) {
                $masked[$k] = '***REDACTED***';
            }
        }
        return $masked;
    }
}
