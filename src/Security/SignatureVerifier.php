<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Security;

/**
 * Verifies HMAC-SHA256 signatures on inbound Intuit QBO webhook requests
 * per the `intuit-signature` header spec.
 *
 * Wire format (per Intuit's webhooks doc):
 *
 *     intuit-signature: <base64(hmac_sha256(verifier_token, raw_body))>
 *
 * Verification uses hash_equals to keep comparison constant-time.
 * Returns void on success, throws SignatureException on any failure.
 *
 * Note: unlike a Stripe-style `t=...,v1=...` envelope, Intuit's signature
 * does NOT carry a timestamp. Replay defense is layered on top via
 * ReplayCache, keyed on a hash of the raw body (the body itself contains
 * `lastUpdated` per entity, which provides freshness signal).
 */
final class SignatureVerifier
{
    public const HEADER_NAME = 'intuit-signature';

    public function __construct(
        private readonly string $verifierToken,
    ) {
    }

    /**
     * @throws SignatureException
     */
    public function verify(string $rawBody, ?string $headerValue): void
    {
        if ($headerValue === null || $headerValue === '') {
            throw new SignatureException('intuit-signature header missing');
        }
        $provided = base64_decode($headerValue, true);
        if ($provided === false) {
            throw new SignatureException('intuit-signature header is not valid base64');
        }
        $expected = hash_hmac('sha256', $rawBody, $this->verifierToken, true);
        if (!hash_equals($expected, $provided)) {
            throw new SignatureException('intuit-signature mismatch');
        }
    }

    /**
     * Produce a signed header value for use by integration test harnesses.
     * Mirrors Intuit's algorithm so test fixtures stay realistic.
     */
    public function sign(string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $this->verifierToken, true));
    }
}
