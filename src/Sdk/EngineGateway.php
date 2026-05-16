<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Sdk;

use OpenSalesTax\Address;
use OpenSalesTax\Client as OstClient;
use OpenSalesTax\Exceptions\OpenSalesTaxException;
use OpenSalesTax\LineItem;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\InvoicePayload;
use OpenSalesTax\QuickBooksOnline\Security\UrlValidator;
use OpenSalesTax\Responses\CalculateResponse;
use Psr\Log\LoggerInterface;

/**
 * Bridge between the sidecar's InvoicePayload type and the OpenSalesTax PHP
 * SDK (`ejosterberg/opensalestax` ^0.1).
 *
 * Failure model: any SDK exception is converted to a single null return so
 * the caller has one path to handle. The webhook handler decides what to do:
 * fail-soft (default; leave invoice untouched) or fail-hard (throw, becomes
 * 500 to Intuit, which retries).
 */
final class EngineGateway
{
    public function __construct(
        private readonly OstClient $client,
        private readonly UrlValidator $urlValidator,
        private readonly string $engineUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the engine response, or null on any fail-soft path (logged).
     */
    public function calculate(InvoicePayload $payload): ?CalculateResponse
    {
        try {
            $this->urlValidator->validate($this->engineUrl);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('engine URL rejected by SSRF validator', [
                'reason' => $e->getMessage(),
            ]);
            return null;
        }

        try {
            $address = new Address($payload->zip5, $payload->zip4);
            $lines = [];
            foreach ($payload->lines as $line) {
                $lines[] = new LineItem($line->subtotal, 'general');
            }
            $start = microtime(true);
            $response = $this->client->calculate($address, $lines);
            $rttMs = (int) round((microtime(true) - $start) * 1000);
            $this->logger->info('engine /v1/calculate ok', [
                'invoice_id' => $payload->invoiceId,
                'zip5' => $payload->zip5,
                'line_count' => count($payload->lines),
                'tax_total' => $response->taxTotal,
                'rtt_ms' => $rttMs,
            ]);
            return $response;
        } catch (OpenSalesTaxException $e) {
            $this->logger->error('engine call failed', [
                'invoice_id' => $payload->invoiceId,
                'reason' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
