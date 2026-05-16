<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\QuickBooksOnline;

use OpenSalesTax\Responses\CalculateResponse;

/**
 * Builds the sparse-update payload that writes an OST-computed
 * TxnTaxDetail back onto a QBO invoice.
 *
 * In v0.1 we collapse all jurisdictional tax to a single weighted-average
 * line on `TxnTaxDetail.TotalTax` (no `TaxLine` / `TaxRateRef` — that
 * would require a pre-existing TaxRate entity in the company, which the
 * sidecar doesn't manage in v0.1).
 *
 * The output is a sparse update — QBO requires at minimum:
 *   - `Id`        (the invoice id)
 *   - `SyncToken` (optimistic-concurrency token from the fetch)
 *   - `sparse`    set to true
 *
 * Plus whatever fields you actually want changed. Anything you don't
 * include is left as-is.
 */
final class TaxApplier
{
    /**
     * Compute the weighted-average tax rate (percent) the engine produced.
     */
    public static function effectiveRatePercent(CalculateResponse $response): float
    {
        $subtotal = (float) $response->subtotal;
        $tax = (float) $response->taxTotal;
        if ($subtotal <= 0.0) {
            return 0.0;
        }
        return round(($tax / $subtotal) * 100.0, 4);
    }

    /**
     * @param array<string, mixed> $invoice the fetched QBO Invoice object
     * @return array<string, mixed> the sparse-update body for `POST /v3/company/{id}/invoice`
     */
    public function buildSparseUpdate(array $invoice, CalculateResponse $response): array
    {
        $invoiceId = $invoice['Id'] ?? null;
        if (!is_string($invoiceId) && !is_int($invoiceId)) {
            throw new \InvalidArgumentException('Invoice missing Id');
        }
        $syncToken = $invoice['SyncToken'] ?? null;
        if (!is_string($syncToken) && !is_int($syncToken)) {
            throw new \InvalidArgumentException('Invoice missing SyncToken (required for sparse update)');
        }

        $rate = self::effectiveRatePercent($response);
        return [
            'Id' => (string) $invoiceId,
            'SyncToken' => (string) $syncToken,
            'sparse' => true,
            'TxnTaxDetail' => [
                'TotalTax' => round((float) $response->taxTotal, 2),
                // We attach a comment-style label so QBO operators can see
                // where the number came from when they audit the invoice.
                // QBO accepts the field but does not display it in default
                // UI; it is round-tripped via the API.
                'TxnTaxCodeRef' => null,
                '_OstaxRatePercent' => $rate,
            ],
        ];
    }
}
