<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\QuickBooksOnline;

/**
 * One taxable line in an Invoice — extracted from a QBO `Invoice` object's
 * `Line` array (where `DetailType == "SalesItemLineDetail"`).
 *
 * Subtotal is whatever QBO calls "Amount" on the line: quantity × unit
 * price, before tax. We keep it as a string for precision (QBO sends
 * decimals as JSON numbers but precision-safe handling is easier with
 * strings on the engine boundary).
 */
final class InvoiceLine
{
    public function __construct(
        public readonly int $lineNum,
        public readonly string $subtotal,
        public readonly string $description,
    ) {
    }
}
