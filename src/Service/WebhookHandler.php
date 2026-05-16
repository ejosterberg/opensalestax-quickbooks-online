<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Service;

use OpenSalesTax\QuickBooksOnline\Http\Request;
use OpenSalesTax\QuickBooksOnline\Http\Response;
use OpenSalesTax\QuickBooksOnline\Security\RateLimiter;
use OpenSalesTax\QuickBooksOnline\Security\ReplayCache;
use OpenSalesTax\QuickBooksOnline\Security\SignatureException;
use OpenSalesTax\QuickBooksOnline\Security\SignatureVerifier;
use Psr\Log\LoggerInterface;

/**
 * Sidecar webhook handler — the heart of the integration.
 *
 * Pipeline for `POST /webhooks/quickbooks-online`:
 *
 *   1. Rate-limit by source IP. Reject 429 if over.
 *   2. HMAC signature verify per Intuit's `intuit-signature` spec.
 *      Reject 401 on missing / invalid.
 *   3. Replay check (SHA-256 of body within window). Reject 409 on repeat.
 *   4. JSON decode + extract WebhookEvent list. 400 on malformed.
 *   5. For each Invoice.Create / Invoice.Update event: hand to
 *      InvoiceProcessor.
 *   6. Return 200 with a structured summary (per-event applied status).
 *
 * Health endpoint: `GET /health` returns 200 + version. No auth.
 *
 * Constitution §10 disclaimer is included in the 200 response body.
 */
final class WebhookHandler
{
    public const VERSION = '0.1.0-alpha.1';
    public const WEBHOOK_PATH = '/webhooks/quickbooks-online';

    /** @var list<string> */
    private const HANDLED_ENTITIES = ['Invoice'];
    /** @var list<string> */
    private const HANDLED_OPERATIONS = ['Create', 'Update'];

    public function __construct(
        private readonly SignatureVerifier $signature,
        private readonly ReplayCache $replayCache,
        private readonly RateLimiter $rateLimiter,
        private readonly InvoiceProcessorInterface $invoiceProcessor,
        private readonly LoggerInterface $logger,
        private readonly bool $failHard = false,
    ) {
    }

    public function handle(Request $req): Response
    {
        $routed = $this->route($req);
        if ($routed !== null) {
            return $routed;
        }
        $rejected = $this->reject($req);
        if ($rejected !== null) {
            return $rejected;
        }
        return $this->processBody($req);
    }

    private function route(Request $req): ?Response
    {
        if ($req->method === 'GET' && $req->path === '/health') {
            return Response::json(200, [
                'status' => 'ok',
                'service' => 'opensalestax-quickbooks-online',
                'version' => self::VERSION,
            ]);
        }
        if ($req->method !== 'POST' || $req->path !== self::WEBHOOK_PATH) {
            return Response::plain(404, 'not found');
        }
        return null;
    }

    private function reject(Request $req): ?Response
    {
        if (!$this->rateLimiter->allow($req->sourceIp)) {
            $this->logger->warning('webhook rate-limited', ['source_ip' => $req->sourceIp]);
            return Response::plain(429, 'rate limit exceeded');
        }
        try {
            $this->signature->verify(
                $req->body,
                $req->header(SignatureVerifier::HEADER_NAME),
            );
        } catch (SignatureException $e) {
            $this->logger->warning('webhook signature rejected', [
                'source_ip' => $req->sourceIp,
                'reason' => $e->getMessage(),
            ]);
            return Response::plain(401, 'unauthorized');
        }
        if (!$this->replayCache->checkAndRemember($req->body)) {
            $this->logger->warning('webhook replay detected', ['source_ip' => $req->sourceIp]);
            return Response::plain(409, 'replay');
        }
        return null;
    }

    private function processBody(Request $req): Response
    {
        try {
            $decoded = json_decode($req->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('webhook body not valid JSON', ['reason' => $e->getMessage()]);
            return Response::plain(400, 'malformed JSON');
        }
        if (!is_array($decoded)) {
            return Response::plain(400, 'JSON body must be an object');
        }
        /** @var array<string, mixed> $decoded */
        $events = WebhookEvent::listFromBody($decoded);
        if ($events === []) {
            $this->logger->info('webhook had no actionable events');
            return Response::json(200, [
                'processed' => 0,
                'results' => [],
                'disclaimer' => Response::DISCLAIMER,
            ]);
        }

        $results = [];
        $hadError = false;
        foreach ($events as $event) {
            if (!in_array($event->entityName, self::HANDLED_ENTITIES, true)) {
                continue;
            }
            if (!in_array($event->operation, self::HANDLED_OPERATIONS, true)) {
                continue;
            }
            try {
                $result = $this->invoiceProcessor->process($event->realmId, $event->entityId);
            } catch (\Throwable $e) {
                $this->logger->error('webhook processor threw', [
                    'realm_id' => $event->realmId,
                    'invoice_id' => $event->entityId,
                    'reason' => $e->getMessage(),
                ]);
                if ($this->failHard) {
                    return Response::plain(500, 'processor error');
                }
                $hadError = true;
                $result = [
                    'invoice_id' => $event->entityId,
                    'applied' => false,
                    'reason' => 'processor_error',
                ];
            }
            $result['realm_id'] = $event->realmId;
            $results[] = $result;
        }

        return Response::json(200, [
            'processed' => count($results),
            'had_error' => $hadError,
            'results' => $results,
            'disclaimer' => Response::DISCLAIMER,
        ]);
    }
}
