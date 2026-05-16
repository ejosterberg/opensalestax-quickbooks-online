<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Logging;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal stderr JSON logger. No file handles, no buffering, no PII —
 * just a one-line JSON event per call.
 *
 * Anything matching the redact list is replaced with "***REDACTED***"
 * before serialization. The redact list is the set of keys the sidecar
 * promises never to log.
 */
final class StderrLogger extends AbstractLogger
{
    private const REDACT_KEYS = [
        'authorization', 'Authorization',
        'intuit-signature', 'Intuit-Signature',
        'x-api-key', 'X-API-Key', 'X-Api-Key',
        'api_token', 'api_key',
        'access_token', 'refresh_token',
        'client_secret', 'QBO_CLIENT_SECRET',
        'QBO_WEBHOOK_VERIFIER_TOKEN', 'QBO_TOKEN_ENCRYPTION_KEY',
        'OST_API_KEY',
        'signature', 'secret', 'password', 'token',
    ];

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $payload = [
            'ts' => date('c'),
            'level' => is_string($level) ? $level : 'info',
            'msg' => (string) $message,
        ];
        if ($context !== []) {
            $payload['ctx'] = self::redact($context);
        }
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        $stderr = fopen('php://stderr', 'wb');
        if ($stderr === false) {
            return;
        }
        fwrite($stderr, $line . "\n");
        fclose($stderr);
    }

    /**
     * @param array<mixed> $ctx
     * @return array<mixed>
     */
    public static function redact(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            if (is_string($k) && self::isRedactedKey($k)) {
                $out[$k] = '***REDACTED***';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = self::redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function isRedactedKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::REDACT_KEYS as $needle) {
            if (strtolower($needle) === $lower) {
                return true;
            }
        }
        return false;
    }
}
