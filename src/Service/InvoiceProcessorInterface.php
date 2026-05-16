<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Service;

/**
 * Contract for the per-invoice calculation+writeback orchestrator.
 *
 * Exists primarily so WebhookHandler can be unit-tested against a
 * stubbed implementation without booting the full QboClient / engine
 * stack.
 */
interface InvoiceProcessorInterface
{
    /**
     * Process one invoice: fetch, build payload, call engine, write back.
     *
     * @return array{invoice_id: string, applied: bool, reason?: string, tax_total?: float, tax_rate_pct?: float}
     */
    public function process(string $realmId, string $invoiceId): array;
}
