<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Service;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenSalesTax\Client as OstClient;
use OpenSalesTax\QuickBooksOnline\Oauth\EncryptedFileTokenStore;
use OpenSalesTax\QuickBooksOnline\Oauth\OAuthFlow;
use OpenSalesTax\QuickBooksOnline\Oauth\TokenSet;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\InvoicePayloadBuilder;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\QboClient;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\TaxApplier;
use OpenSalesTax\QuickBooksOnline\Sdk\EngineGateway;
use OpenSalesTax\QuickBooksOnline\Security\UrlValidator;
use OpenSalesTax\QuickBooksOnline\Service\InvoiceProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InvoiceProcessorTest extends TestCase
{
    private string $tokensPath = '';

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/ostax-qbo-proc-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0o700, true);
        $this->tokensPath = $tmp . '/tokens.json';
        $store = new EncryptedFileTokenStore($this->tokensPath, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        // Pre-seed a token that's not expired so the processor doesn't try to
        // refresh during the test.
        $store->save(new TokenSet(
            realmId: '999',
            accessToken: 'access',
            accessTokenExpiresAt: time() + 3600,
            refreshToken: 'refresh',
            refreshTokenExpiresAt: time() + 8_640_000,
        ));
    }

    protected function tearDown(): void
    {
        if (is_file($this->tokensPath)) {
            @unlink($this->tokensPath);
        }
        @rmdir(dirname($this->tokensPath));
    }

    /**
     * @param array<int, array<string, mixed>>|null $qboHistory output ref — passed in
     *   empty by the caller; the Guzzle history middleware appends to it as
     *   the processor makes requests.
     */
    private function makeProcessor(
        MockHandler $qboMock,
        MockHandler $engineMock,
        ?array &$qboHistory = null,
    ): InvoiceProcessor {
        if ($qboHistory === null) {
            $qboHistory = [];
        }
        // Recreate the store with whatever key we already used (need to read
        // from disk to get the same one). Easier: keep the file but re-derive
        // the store with a new key — then we'll pre-save again here. We can't
        // do that cleanly without coupling — instead use the same path with
        // a token we save inline via a fresh store + key.
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        @unlink($this->tokensPath);
        $store = new EncryptedFileTokenStore($this->tokensPath, $key);
        $store->save(new TokenSet(
            realmId: '999',
            accessToken: 'access',
            accessTokenExpiresAt: time() + 3600,
            refreshToken: 'refresh',
            refreshTokenExpiresAt: time() + 8_640_000,
        ));

        $logger = new NullLogger();
        $urlValidator = new UrlValidator(allowPrivateNetworks: true);

        $qboStack = HandlerStack::create($qboMock);
        $qboStack->push(\GuzzleHttp\Middleware::history($qboHistory));
        $qboGuzzle = new GuzzleClient(['handler' => $qboStack]);

        $oauth = new OAuthFlow(
            clientId: 'CID',
            clientSecret: 'SEC',
            redirectUri: 'http://localhost/cb',
            http: new GuzzleClient(['handler' => HandlerStack::create(new MockHandler())]),
        );
        $qbo = new QboClient(
            tokenStore: $store,
            oauth: $oauth,
            environment: 'sandbox',
            urlValidator: $urlValidator,
            http: $qboGuzzle,
            logger: $logger,
        );

        $engineGuzzle = new GuzzleClient(['handler' => HandlerStack::create($engineMock)]);
        $ost = new OstClient(baseUrl: 'https://ost.example.com', httpClient: $engineGuzzle);
        $engine = new EngineGateway(
            client: $ost,
            urlValidator: $urlValidator,
            engineUrl: 'https://ost.example.com',
            logger: $logger,
        );

        return new InvoiceProcessor(
            qbo: $qbo,
            payloadBuilder: new InvoicePayloadBuilder(),
            engine: $engine,
            taxApplier: new TaxApplier(),
            logger: $logger,
        );
    }

    public function testHappyPath(): void
    {
        $invoiceBody = (string) json_encode(['Invoice' => [
            'Id' => '145',
            'SyncToken' => '0',
            'CurrencyRef' => ['value' => 'USD'],
            'ShipAddr' => ['PostalCode' => '55401', 'CountryCode' => 'US'],
            'Line' => [[
                'LineNum' => 1, 'Amount' => 100.00,
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => 100.00],
            ]],
        ]]);
        $qboMock = new MockHandler([
            new GuzzleResponse(200, [], $invoiceBody),
            new GuzzleResponse(200, [], '{"Invoice":{}}'),
        ]);
        $engineMock = new MockHandler([
            new GuzzleResponse(200, [], (string) json_encode([
                'subtotal' => '100.00',
                'tax_total' => '9.03',
                'total' => '109.03',
                'lines' => [],
            ])),
        ]);
        $history = [];
        $processor = $this->makeProcessor($qboMock, $engineMock, $history);
        $result = $processor->process('999', '145');
        $this->assertTrue($result['applied']);
        $this->assertSame(9.03, $result['tax_total']);
        $this->assertCount(2, $history, 'expected one fetch + one update');
    }

    public function testNonUsdInvoiceShortCircuits(): void
    {
        $invoiceBody = (string) json_encode(['Invoice' => [
            'Id' => '145', 'SyncToken' => '0',
            'CurrencyRef' => ['value' => 'EUR'],
            'ShipAddr' => ['PostalCode' => '55401', 'CountryCode' => 'US'],
            'Line' => [[
                'LineNum' => 1, 'Amount' => 100.00,
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => 100.00],
            ]],
        ]]);
        $qboMock = new MockHandler([new GuzzleResponse(200, [], $invoiceBody)]);
        $engineMock = new MockHandler();
        $history = [];
        $processor = $this->makeProcessor($qboMock, $engineMock, $history);
        $result = $processor->process('999', '145');
        $this->assertFalse($result['applied']);
        $this->assertSame('out_of_scope', $result['reason']);
        $this->assertCount(1, $history, 'should not call QBO again after short-circuit');
    }

    public function testEngineErrorIsFailSoft(): void
    {
        $invoiceBody = (string) json_encode(['Invoice' => [
            'Id' => '145', 'SyncToken' => '0',
            'CurrencyRef' => ['value' => 'USD'],
            'ShipAddr' => ['PostalCode' => '55401', 'CountryCode' => 'US'],
            'Line' => [[
                'LineNum' => 1, 'Amount' => 100.00,
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => 100.00],
            ]],
        ]]);
        $qboMock = new MockHandler([new GuzzleResponse(200, [], $invoiceBody)]);
        $engineMock = new MockHandler([new GuzzleResponse(500, [], '{}')]);
        $history = [];
        $processor = $this->makeProcessor($qboMock, $engineMock, $history);
        $result = $processor->process('999', '145');
        $this->assertFalse($result['applied']);
        $this->assertSame('engine_unavailable', $result['reason']);
        $this->assertCount(1, $history, 'no update call on engine error');
    }

    public function testQboFetchFailureFailSoft(): void
    {
        $qboMock = new MockHandler([new GuzzleResponse(500, [], 'err')]);
        $engineMock = new MockHandler();
        $history = [];
        $processor = $this->makeProcessor($qboMock, $engineMock, $history);
        $result = $processor->process('999', '145');
        $this->assertFalse($result['applied']);
        $this->assertSame('qbo_fetch_failed', $result['reason']);
    }
}
