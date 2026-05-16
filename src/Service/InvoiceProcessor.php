<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Service;

use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\InvoicePayloadBuilder;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\PayloadException;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\QboClient;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\QboClientException;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\TaxApplier;
use OpenSalesTax\QuickBooksOnline\Sdk\EngineGateway;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the per-invoice calculation+writeback pipeline.
 *
 * Decoupled from the HTTP layer so the same logic can be invoked from:
 *   - WebhookHandler (one event per entity in the inbound webhook batch)
 *   - bin/console tax:recalc <invoice_id>  (manual recompute)
 */
final class InvoiceProcessor implements InvoiceProcessorInterface
{
    public function __construct(
        private readonly QboClient $qbo,
        private readonly InvoicePayloadBuilder $payloadBuilder,
        private readonly EngineGateway $engine,
        private readonly TaxApplier $taxApplier,
        private readonly LoggerInterface $logger,
        private readonly bool $failHard = false,
    ) {
    }

    /**
     * Process one invoice: fetch, build payload, call engine, write back.
     *
     * @return array{invoice_id: string, applied: bool, reason?: string, tax_total?: float, tax_rate_pct?: float}
     */
    public function process(string $realmId, string $invoiceId): array
    {
        try {
            $invoice = $this->qbo->fetchInvoice($realmId, $invoiceId);
        } catch (QboClientException $e) {
            $this->logger->error('qbo fetch invoice failed', [
                'realm_id' => $realmId,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);
            if ($this->failHard) {
                throw $e;
            }
            return [
                'invoice_id' => $invoiceId,
                'applied' => false,
                'reason' => 'qbo_fetch_failed',
            ];
        }

        try {
            $payload = $this->payloadBuilder->build($invoice);
        } catch (PayloadException $e) {
            $this->logger->info('invoice not in scope (US + USD only)', [
                'realm_id' => $realmId,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);
            return [
                'invoice_id' => $invoiceId,
                'applied' => false,
                'reason' => 'out_of_scope',
            ];
        }

        $response = $this->engine->calculate($payload);
        if ($response === null) {
            if ($this->failHard) {
                throw new QboClientException("OST engine error (fail-hard mode)");
            }
            return [
                'invoice_id' => $invoiceId,
                'applied' => false,
                'reason' => 'engine_unavailable',
            ];
        }

        $update = $this->taxApplier->buildSparseUpdate($invoice, $response);
        try {
            $this->qbo->updateInvoiceSparse($realmId, $update);
        } catch (QboClientException $e) {
            $this->logger->error('qbo invoice update failed', [
                'realm_id' => $realmId,
                'invoice_id' => $invoiceId,
                'reason' => $e->getMessage(),
            ]);
            if ($this->failHard) {
                throw $e;
            }
            return [
                'invoice_id' => $invoiceId,
                'applied' => false,
                'reason' => 'qbo_update_failed',
            ];
        }

        return [
            'invoice_id' => $invoiceId,
            'applied' => true,
            'tax_total' => round((float) $response->taxTotal, 2),
            'tax_rate_pct' => TaxApplier::effectiveRatePercent($response),
        ];
    }
}
