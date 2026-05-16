<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Oauth;

/**
 * On-disk token store: a single JSON file mapping realm id to an encrypted
 * blob (XSalsa20-Poly1305 via libsodium `sodium_crypto_secretbox`).
 *
 * Each blob format on disk:
 *
 *   { "realm_id": { "n": "<base64-nonce>", "c": "<base64-ciphertext>" } }
 *
 * The encryption key is the raw 32-byte secret derived from
 * `QBO_TOKEN_ENCRYPTION_KEY` (Config decodes the base64). The plaintext
 * is JSON-serialized TokenSet.
 *
 * This class is intentionally simple. Multi-process locking is left to
 * the operator (a single sidecar process per host is the supported
 * deployment shape in v0.1).
 */
final class EncryptedFileTokenStore implements TokenStore
{
    public function __construct(
        private readonly string $path,
        private readonly string $key,
    ) {
        if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new TokenStoreException(sprintf(
                'Encryption key must be exactly %d bytes (got %d)',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($this->key),
            ));
        }
    }

    public function save(TokenSet $tokens): void
    {
        $existing = $this->readFile();
        $existing[$tokens->realmId] = $this->encrypt($tokens);
        $this->writeFile($existing);
    }

    public function load(string $realmId): ?TokenSet
    {
        $existing = $this->readFile();
        if (!isset($existing[$realmId])) {
            return null;
        }
        $entry = $existing[$realmId];
        if (
            !is_array($entry) || !isset($entry['n'], $entry['c'])
            || !is_string($entry['n']) || !is_string($entry['c'])
        ) {
            throw new TokenStoreException("Token store entry for realm {$realmId} is malformed");
        }
        return $this->decrypt($entry);
    }

    public function listRealms(): array
    {
        $existing = $this->readFile();
        $out = [];
        foreach (array_keys($existing) as $k) {
            // JSON object keys come back as ints when fully numeric (PHP
            // coerces them); cast to string so realm ids are always strings.
            $out[] = (string) $k;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new TokenStoreException("Cannot read token store at {$this->path}");
        }
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TokenStoreException("Token store JSON malformed: " . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new TokenStoreException("Token store root must be a JSON object");
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeFile(array $data): void
    {
        $dir = dirname($this->path);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            if (!@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                throw new TokenStoreException("Cannot create token store directory: {$dir}");
            }
        }
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new TokenStoreException('Failed to encode token store JSON');
        }
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new TokenStoreException("Cannot write token store at {$this->path}");
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new TokenStoreException("Cannot atomically replace token store at {$this->path}");
        }
    }

    /**
     * @return array{n: string, c: string}
     */
    private function encrypt(TokenSet $tokens): array
    {
        $plaintext = json_encode($tokens->toArray(), JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return [
            'n' => base64_encode($nonce),
            'c' => base64_encode($ciphertext),
        ];
    }

    /**
     * @param array{n: string, c: string} $entry
     */
    private function decrypt(array $entry): TokenSet
    {
        $nonce = base64_decode($entry['n'], true);
        $cipher = base64_decode($entry['c'], true);
        if ($nonce === false || $cipher === false) {
            throw new TokenStoreException('Token store entry is not valid base64');
        }
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plaintext === false) {
            throw new TokenStoreException('Token store entry failed authentication (wrong key or tampered)');
        }
        try {
            $decoded = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TokenStoreException('Decrypted token blob is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new TokenStoreException('Decrypted token blob is not a JSON object');
        }
        /** @var array<string, mixed> $decoded */
        return TokenSet::fromArray($decoded);
    }
}
