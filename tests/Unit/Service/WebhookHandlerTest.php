<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Service;

use OpenSalesTax\QuickBooksOnline\Http\Request;
use OpenSalesTax\QuickBooksOnline\Security\RateLimiter;
use OpenSalesTax\QuickBooksOnline\Security\ReplayCache;
use OpenSalesTax\QuickBooksOnline\Security\SignatureVerifier;
use OpenSalesTax\QuickBooksOnline\Service\InvoiceProcessorInterface;
use OpenSalesTax\QuickBooksOnline\Service\WebhookHandler;
use OpenSalesTax\QuickBooksOnline\Tests\Unit\TestSupport\JsonAssert;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebhookHandlerTest extends TestCase
{
    private const TOKEN = 'verifier-token-XXXX';

    /**
     * @param list<array{realmId: string, invoiceId: string, result: array<string, mixed>}> $expectedCalls
     */
    private function handler(array &$expectedCalls = []): WebhookHandler
    {
        $logger = new NullLogger();
        // Anonymous-class stub of InvoiceProcessorInterface — captures the
        // realm/invoice pairs the handler hands it and returns canned results.
        $processor = new class ($expectedCalls) implements InvoiceProcessorInterface {
            /** @var list<array{realmId: string, invoiceId: string, result: array<string, mixed>}> */
            public array $calls;

            /**
             * @param list<array{realmId: string, invoiceId: string, result: array<string, mixed>}> $expectedCalls
             */
            public function __construct(array &$expectedCalls)
            {
                $this->calls = &$expectedCalls;
            }

            public function process(string $realmId, string $invoiceId): array
            {
                $this->calls[] = ['realmId' => $realmId, 'invoiceId' => $invoiceId, 'result' => []];
                return [
                    'invoice_id' => $invoiceId,
                    'applied' => true,
                    'tax_total' => 9.03,
                    'tax_rate_pct' => 9.025,
                ];
            }
        };

        return new WebhookHandler(
            signature: new SignatureVerifier(self::TOKEN),
            replayCache: new ReplayCache(ttlSeconds: 60),
            rateLimiter: new RateLimiter(100),
            invoiceProcessor: $processor,
            logger: $logger,
        );
    }

    private static function body(): string
    {
        return json_encode([
            'eventNotifications' => [[
                'realmId' => '999',
                'dataChangeEvent' => ['entities' => [[
                    'name' => 'Invoice', 'id' => '145', 'operation' => 'Create',
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private static function signed(string $body): string
    {
        return (new SignatureVerifier(self::TOKEN))->sign($body);
    }

    private static function postRequest(string $body, ?string $sig): Request
    {
        $headers = [];
        if ($sig !== null) {
            $headers['intuit-signature'] = $sig;
        }
        return new Request(
            method: 'POST',
            path: WebhookHandler::WEBHOOK_PATH,
            headers: $headers,
            body: $body,
            sourceIp: '198.51.100.7',
        );
    }

    public function testHealthEndpointReturns200(): void
    {
        $resp = $this->handler()->handle(new Request(
            method: 'GET',
            path: '/health',
            headers: [],
            body: '',
            sourceIp: '198.51.100.7',
        ));
        $this->assertSame(200, $resp->status);
        $decoded = JsonAssert::decodeObject($resp->body);
        $this->assertSame('ok', $decoded['status']);
    }

    public function testUnknownPathReturns404(): void
    {
        $resp = $this->handler()->handle(new Request(
            method: 'POST',
            path: '/nope',
            headers: [],
            body: '',
            sourceIp: '198.51.100.7',
        ));
        $this->assertSame(404, $resp->status);
    }

    public function testMissingSignatureReturns401(): void
    {
        $body = self::body();
        $resp = $this->handler()->handle(self::postRequest($body, null));
        $this->assertSame(401, $resp->status);
    }

    public function testInvalidSignatureReturns401(): void
    {
        $resp = $this->handler()->handle(self::postRequest(self::body(), 'bogus'));
        $this->assertSame(401, $resp->status);
    }

    public function testValidSignedRequestProcessesInvoice(): void
    {
        $calls = [];
        $body = self::body();
        $resp = $this->handler($calls)->handle(self::postRequest($body, self::signed($body)));
        $this->assertSame(200, $resp->status);
        $decoded = JsonAssert::decodeObject($resp->body);
        $this->assertSame(1, $decoded['processed']);
        $this->assertCount(1, $calls);
        $this->assertSame('999', $calls[0]['realmId']);
        $this->assertSame('145', $calls[0]['invoiceId']);
    }

    public function testReplayRejected(): void
    {
        $handler = $this->handler();
        $body = self::body();
        $sig = self::signed($body);
        $handler->handle(self::postRequest($body, $sig));
        $resp2 = $handler->handle(self::postRequest($body, $sig));
        $this->assertSame(409, $resp2->status);
    }

    public function testMalformedJsonReturns400(): void
    {
        $body = '{not json';
        $resp = $this->handler()->handle(self::postRequest($body, self::signed($body)));
        $this->assertSame(400, $resp->status);
    }

    public function testNonInvoiceEntityIgnored(): void
    {
        $calls = [];
        $body = json_encode([
            'eventNotifications' => [[
                'realmId' => '999',
                'dataChangeEvent' => ['entities' => [[
                    'name' => 'Customer', 'id' => '7', 'operation' => 'Create',
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $resp = $this->handler($calls)->handle(self::postRequest($body, self::signed($body)));
        $this->assertSame(200, $resp->status);
        $this->assertSame([], $calls);
    }

    public function testDeleteOperationIgnored(): void
    {
        $calls = [];
        $body = json_encode([
            'eventNotifications' => [[
                'realmId' => '999',
                'dataChangeEvent' => ['entities' => [[
                    'name' => 'Invoice', 'id' => '145', 'operation' => 'Delete',
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $resp = $this->handler($calls)->handle(self::postRequest($body, self::signed($body)));
        $this->assertSame(200, $resp->status);
        $this->assertSame([], $calls);
    }
}
