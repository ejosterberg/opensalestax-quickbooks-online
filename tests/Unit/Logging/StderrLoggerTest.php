<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Logging;

use OpenSalesTax\QuickBooksOnline\Logging\StderrLogger;
use PHPUnit\Framework\TestCase;

final class StderrLoggerTest extends TestCase
{
    public function testRedactsAuthorizationHeader(): void
    {
        $redacted = StderrLogger::redact(['Authorization' => 'Bearer abcdef']);
        $this->assertSame('***REDACTED***', $redacted['Authorization']);
    }

    public function testRedactsIntuitSignatureCaseInsensitive(): void
    {
        $redacted = StderrLogger::redact(['Intuit-Signature' => 'XYZ']);
        $this->assertSame('***REDACTED***', $redacted['Intuit-Signature']);
        $redacted2 = StderrLogger::redact(['intuit-signature' => 'XYZ']);
        $this->assertSame('***REDACTED***', $redacted2['intuit-signature']);
    }

    public function testRedactsRefreshAndAccessTokens(): void
    {
        $redacted = StderrLogger::redact([
            'refresh_token' => 'r-XXXX',
            'access_token' => 'a-YYYY',
            'invoice_id' => '145',
        ]);
        $this->assertSame('***REDACTED***', $redacted['refresh_token']);
        $this->assertSame('***REDACTED***', $redacted['access_token']);
        $this->assertSame('145', $redacted['invoice_id']);
    }

    public function testRedactsNestedArrays(): void
    {
        $redacted = StderrLogger::redact([
            'request' => [
                'headers' => ['Authorization' => 'token', 'X-Forwarded-For' => '1.2.3.4'],
            ],
        ]);
        $req = $redacted['request'];
        $this->assertIsArray($req);
        $hdrs = $req['headers'];
        $this->assertIsArray($hdrs);
        $this->assertSame('***REDACTED***', $hdrs['Authorization']);
        $this->assertSame('1.2.3.4', $hdrs['X-Forwarded-For']);
    }
}
