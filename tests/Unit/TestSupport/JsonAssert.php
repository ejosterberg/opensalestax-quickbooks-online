<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\TestSupport;

use PHPUnit\Framework\Assert;

/**
 * Test-only helper that JSON-decodes a string and asserts it is an array.
 */
final class JsonAssert
{
    /**
     * @return array<string, mixed>
     */
    public static function decodeObject(string $body): array
    {
        $decoded = json_decode($body, true);
        Assert::assertIsArray($decoded, 'expected JSON object');
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
