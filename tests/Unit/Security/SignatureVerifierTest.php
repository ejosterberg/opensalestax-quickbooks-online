<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Security;

use OpenSalesTax\QuickBooksOnline\Security\SignatureException;
use OpenSalesTax\QuickBooksOnline\Security\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private const TOKEN = 'webhook-verifier-token-XYZ';

    public function testRoundTripVerifies(): void
    {
        $verifier = new SignatureVerifier(self::TOKEN);
        $body = '{"eventNotifications":[]}';
        $sig = $verifier->sign($body);
        $verifier->verify($body, $sig);
        $this->assertTrue(true, 'no exception');
    }

    public function testMissingHeaderRejected(): void
    {
        $verifier = new SignatureVerifier(self::TOKEN);
        $this->expectException(SignatureException::class);
        $verifier->verify('body', null);
    }

    public function testEmptyHeaderRejected(): void
    {
        $verifier = new SignatureVerifier(self::TOKEN);
        $this->expectException(SignatureException::class);
        $verifier->verify('body', '');
    }

    public function testInvalidBase64Rejected(): void
    {
        $verifier = new SignatureVerifier(self::TOKEN);
        $this->expectException(SignatureException::class);
        $verifier->verify('body', '@@@not-base64@@@');
    }

    public function testWrongTokenRejected(): void
    {
        $signer = new SignatureVerifier('different-token');
        $body = '{"x":1}';
        $sig = $signer->sign($body);
        $verifier = new SignatureVerifier(self::TOKEN);
        $this->expectException(SignatureException::class);
        $verifier->verify($body, $sig);
    }

    public function testTamperedBodyRejected(): void
    {
        $verifier = new SignatureVerifier(self::TOKEN);
        $sig = $verifier->sign('{"a":1}');
        $this->expectException(SignatureException::class);
        $verifier->verify('{"a":2}', $sig);
    }

    public function testHeaderConstantMatchesIntuitSpec(): void
    {
        $this->assertSame('intuit-signature', SignatureVerifier::HEADER_NAME);
    }
}
