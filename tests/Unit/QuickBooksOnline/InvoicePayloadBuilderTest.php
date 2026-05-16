<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\QuickBooksOnline;

use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\InvoicePayloadBuilder;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\PayloadException;
use PHPUnit\Framework\TestCase;

final class InvoicePayloadBuilderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function minimalInvoice(): array
    {
        return [
            'Id' => '145',
            'SyncToken' => '0',
            'CurrencyRef' => ['value' => 'USD'],
            'ShipAddr' => [
                'PostalCode' => '55401-1234',
                'CountryCode' => 'US',
            ],
            'Line' => [
                [
                    'LineNum' => 1,
                    'Amount' => 100.00,
                    'DetailType' => 'SalesItemLineDetail',
                    'Description' => 'Widget',
                    'SalesItemLineDetail' => ['Qty' => 1, 'UnitPrice' => 100.00],
                ],
                [
                    'Amount' => 100.00,
                    'DetailType' => 'SubTotalLineDetail',
                ],
            ],
        ];
    }

    public function testBuildsMinimalInvoice(): void
    {
        $payload = (new InvoicePayloadBuilder())->build(self::minimalInvoice());
        $this->assertSame('145', $payload->invoiceId);
        $this->assertSame('55401', $payload->zip5);
        $this->assertSame('1234', $payload->zip4);
        $this->assertSame('USD', $payload->currencyCode);
        $this->assertSame('US', $payload->countryCode);
        $this->assertCount(1, $payload->lines);
        $this->assertSame('100', $payload->lines[0]->subtotal);
    }

    public function testNonUsdCurrencyRejected(): void
    {
        $inv = self::minimalInvoice();
        $inv['CurrencyRef']['value'] = 'EUR';
        $this->expectException(PayloadException::class);
        (new InvoicePayloadBuilder())->build($inv);
    }

    public function testNonUsCountryRejected(): void
    {
        $inv = self::minimalInvoice();
        $inv['ShipAddr']['CountryCode'] = 'CA';
        $this->expectException(PayloadException::class);
        (new InvoicePayloadBuilder())->build($inv);
    }

    public function testFallsBackToBillAddrWhenNoShipAddr(): void
    {
        $inv = self::minimalInvoice();
        unset($inv['ShipAddr']);
        $inv['BillAddr'] = ['PostalCode' => '98101', 'Country' => 'USA'];
        $payload = (new InvoicePayloadBuilder())->build($inv);
        $this->assertSame('98101', $payload->zip5);
        $this->assertNull($payload->zip4);
    }

    public function testNoUsableAddressRejected(): void
    {
        $inv = self::minimalInvoice();
        unset($inv['ShipAddr']);
        $this->expectException(PayloadException::class);
        (new InvoicePayloadBuilder())->build($inv);
    }

    public function testInvalidZipRejected(): void
    {
        $inv = self::minimalInvoice();
        $inv['ShipAddr']['PostalCode'] = 'A2';
        $this->expectException(PayloadException::class);
        (new InvoicePayloadBuilder())->build($inv);
    }

    public function testCountryOmittedDefaultsToUs(): void
    {
        $inv = self::minimalInvoice();
        unset($inv['ShipAddr']['CountryCode']);
        $payload = (new InvoicePayloadBuilder())->build($inv);
        $this->assertSame('US', $payload->countryCode);
    }

    public function testNoTaxableLinesRejected(): void
    {
        $inv = self::minimalInvoice();
        $inv['Line'] = [
            ['Amount' => 50, 'DetailType' => 'SubTotalLineDetail'],
        ];
        $this->expectException(PayloadException::class);
        (new InvoicePayloadBuilder())->build($inv);
    }

    public function testNegativeAmountLinesSkipped(): void
    {
        $inv = self::minimalInvoice();
        $inv['Line'][] = [
            'Amount' => -25,
            'DetailType' => 'SalesItemLineDetail',
            'Description' => 'Refund',
        ];
        $payload = (new InvoicePayloadBuilder())->build($inv);
        $this->assertCount(1, $payload->lines);
    }

    public function testCountryLabelVariationsAccepted(): void
    {
        foreach (['US', 'USA', 'United States', 'united states'] as $label) {
            $inv = self::minimalInvoice();
            $inv['ShipAddr']['CountryCode'] = $label;
            $payload = (new InvoicePayloadBuilder())->build($inv);
            $this->assertSame('US', $payload->countryCode);
        }
    }

    public function testInvoiceWithoutIdRejected(): void
    {
        $inv = self::minimalInvoice();
        unset($inv['Id']);
        $this->expectException(PayloadException::class);
        (new InvoicePayloadBuilder())->build($inv);
    }
}
