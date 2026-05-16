<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\QuickBooksOnline;

use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\TaxApplier;
use OpenSalesTax\Responses\CalculateResponse;
use PHPUnit\Framework\TestCase;

final class TaxApplierTest extends TestCase
{
    private function calculateResponse(float $subtotal, float $tax): CalculateResponse
    {
        return CalculateResponse::fromArray([
            'subtotal' => (string) $subtotal,
            'tax_total' => (string) $tax,
            'total' => (string) ($subtotal + $tax),
            'lines' => [],
        ]);
    }

    public function testEffectiveRatePercentBasic(): void
    {
        $resp = $this->calculateResponse(100.00, 9.03);
        $this->assertSame(9.03, TaxApplier::effectiveRatePercent($resp));
    }

    public function testEffectiveRatePercentZeroSubtotal(): void
    {
        $resp = $this->calculateResponse(0.0, 0.0);
        $this->assertSame(0.0, TaxApplier::effectiveRatePercent($resp));
    }

    public function testBuildSparseUpdateHasRequiredFields(): void
    {
        $invoice = ['Id' => '145', 'SyncToken' => '3'];
        $resp = $this->calculateResponse(100.00, 9.03);
        $update = (new TaxApplier())->buildSparseUpdate($invoice, $resp);
        $this->assertSame('145', $update['Id']);
        $this->assertSame('3', $update['SyncToken']);
        $this->assertTrue($update['sparse']);
        $tax = $update['TxnTaxDetail'];
        $this->assertIsArray($tax);
        $this->assertSame(9.03, $tax['TotalTax']);
    }

    public function testBuildSparseUpdateMissingSyncTokenThrows(): void
    {
        $resp = $this->calculateResponse(100.00, 9.03);
        $this->expectException(\InvalidArgumentException::class);
        (new TaxApplier())->buildSparseUpdate(['Id' => '145'], $resp);
    }

    public function testBuildSparseUpdateMissingIdThrows(): void
    {
        $resp = $this->calculateResponse(100.00, 9.03);
        $this->expectException(\InvalidArgumentException::class);
        (new TaxApplier())->buildSparseUpdate(['SyncToken' => '3'], $resp);
    }
}
