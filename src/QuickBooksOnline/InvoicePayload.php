<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\QuickBooksOnline;

/**
 * Typed view of a QBO Invoice trimmed to the fields the OST engine needs.
 *
 * Built by {@see InvoicePayloadBuilder} from a raw QBO API response or an
 * IPPInvoice entity (the SDK's stdClass-style object).
 */
final class InvoicePayload
{
    /**
     * @param InvoiceLine[] $lines
     */
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $zip5,
        public readonly ?string $zip4,
        public readonly string $currencyCode,
        public readonly string $countryCode,
        public readonly array $lines,
    ) {
    }

    public function subtotal(): string
    {
        $total = '0';
        foreach ($this->lines as $line) {
            $total = self::addDecimal($total, $line->subtotal);
        }
        return $total;
    }

    private static function addDecimal(string $a, string $b): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($a, $b, 6);
        }
        return (string) ((float) $a + (float) $b);
    }
}
