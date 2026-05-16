<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Sdk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenSalesTax\Client as OstClient;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\InvoiceLine;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\InvoicePayload;
use OpenSalesTax\QuickBooksOnline\Sdk\EngineGateway;
use OpenSalesTax\QuickBooksOnline\Security\UrlValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EngineGatewayTest extends TestCase
{
    private function gateway(MockHandler $mock, string $url = 'https://ost.example.com'): EngineGateway
    {
        $http = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
        $ost = new OstClient(baseUrl: $url, httpClient: $http);
        return new EngineGateway(
            client: $ost,
            urlValidator: new UrlValidator(allowPrivateNetworks: true),
            engineUrl: $url,
            logger: new NullLogger(),
        );
    }

    private function payload(): InvoicePayload
    {
        return new InvoicePayload(
            invoiceId: '145',
            zip5: '55401',
            zip4: null,
            currencyCode: 'USD',
            countryCode: 'US',
            lines: [new InvoiceLine(1, '100.00', 'Widget')],
        );
    }

    public function testCalculateReturnsResponseOnSuccess(): void
    {
        $body = (string) json_encode([
            'subtotal' => '100.00',
            'tax_total' => '9.03',
            'total' => '109.03',
            'lines' => [],
        ]);
        $gateway = $this->gateway(new MockHandler([new GuzzleResponse(200, [], $body)]));
        $resp = $gateway->calculate($this->payload());
        $this->assertNotNull($resp);
        $this->assertSame('9.03', (string) $resp->taxTotal);
    }

    public function testEngineErrorReturnsNull(): void
    {
        $gateway = $this->gateway(new MockHandler([new GuzzleResponse(500, [], '{}')]));
        $this->assertNull($gateway->calculate($this->payload()));
    }

    public function testRejectsInvalidEngineUrl(): void
    {
        $gateway = $this->gateway(new MockHandler(), 'not-a-url');
        $this->assertNull($gateway->calculate($this->payload()));
    }
}
