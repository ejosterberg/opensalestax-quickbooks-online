<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Security;

/**
 * In-memory replay-attack cache.
 *
 * The cache is bounded; oldest entries fall off when the cap is reached.
 * Production deployments running >1 sidecar replica should swap this for
 * a shared cache (Redis SETNX with TTL) — extension point in v0.2.
 *
 * The cache key is the SHA-256 of the raw body. Same payload re-sent
 * within the replay window is rejected.
 */
final class ReplayCache
{
    /** @var array<string, int> key => first-seen unix seconds */
    private array $seen = [];

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly int $ttlSeconds,
        private readonly int $maxEntries = 10_000,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Returns true if this is a fresh request (record it and continue).
     * Returns false if we've seen this exact body within the TTL — caller
     * should reject.
     */
    public function checkAndRemember(string $rawBody): bool
    {
        $key = hash('sha256', $rawBody);
        $this->evictExpired();
        if (isset($this->seen[$key])) {
            return false;
        }
        if (count($this->seen) >= $this->maxEntries) {
            $oldestKey = array_key_first($this->seen);
            if ($oldestKey !== null) {
                unset($this->seen[$oldestKey]);
            }
        }
        $this->seen[$key] = ($this->clock)();
        return true;
    }

    public function size(): int
    {
        return count($this->seen);
    }

    private function evictExpired(): void
    {
        $cutoff = ($this->clock)() - $this->ttlSeconds;
        foreach ($this->seen as $k => $t) {
            if ($t < $cutoff) {
                unset($this->seen[$k]);
            } else {
                break;
            }
        }
    }
}
