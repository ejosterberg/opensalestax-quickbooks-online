<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\QuickBooksOnline;

/**
 * Adapter from a raw QBO Invoice (associative-array view of the JSON the
 * QBO API returns) to our typed `InvoicePayload`.
 *
 * QBO's invoice payload (abridged):
 *
 *   {
 *     "Id": "145",
 *     "CurrencyRef": {"value": "USD", "name": "United States Dollar"},
 *     "BillAddr": {
 *       "PostalCode": "55401-1234",
 *       "CountryCode": "US",      // not always present
 *       "Country": "USA"          // sometimes here instead
 *     },
 *     "ShipAddr": { ... },        // preferred over BillAddr if present
 *     "Line": [
 *       {
 *         "LineNum": 1,
 *         "Amount": 100.00,
 *         "DetailType": "SalesItemLineDetail",
 *         "Description": "Widget",
 *         "SalesItemLineDetail": { "Qty": 1, "UnitPrice": 100.00 }
 *       },
 *       {
 *         "Amount": 100.00,
 *         "DetailType": "SubTotalLineDetail",         // skip
 *         "SubTotalLineDetail": {}
 *       }
 *     ]
 *   }
 *
 * Only `SalesItemLineDetail` lines are fed to the engine; other line
 * types (subtotals, discounts, group-line headers) are skipped.
 *
 * US/USD gates throw {@see PayloadException} on out-of-scope invoices —
 * the caller turns that into a 204 No Content.
 */
final class InvoicePayloadBuilder
{
    /**
     * @param array<string, mixed> $invoice The decoded QBO Invoice resource
     *   (the object inside `{"Invoice": {...}}`).
     */
    public function build(array $invoice): InvoicePayload
    {
        $invoiceId = self::stringField($invoice, 'Id');
        if ($invoiceId === '') {
            throw new PayloadException('Invoice missing Id');
        }

        $currencyCode = $this->resolveCurrency($invoice);
        [$countryCode, $postal] = $this->resolveAddress($invoice);
        [$zip5, $zip4] = $this->splitZip($postal);

        $lines = $this->extractLines($invoice);
        if ($lines === []) {
            throw new PayloadException('Invoice has no taxable line items');
        }

        return new InvoicePayload($invoiceId, $zip5, $zip4, $currencyCode, $countryCode, $lines);
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function resolveCurrency(array $invoice): string
    {
        $ref = $invoice['CurrencyRef'] ?? null;
        if (!is_array($ref)) {
            throw new PayloadException('Invoice missing CurrencyRef');
        }
        $value = $ref['value'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PayloadException('CurrencyRef.value missing');
        }
        if (strtoupper($value) !== 'USD') {
            throw new PayloadException("Invoice currency '{$value}' is not USD");
        }
        return 'USD';
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array{0: string, 1: string} country code, postal code
     */
    private function resolveAddress(array $invoice): array
    {
        // Prefer ShipAddr (destination-based tax) over BillAddr.
        foreach (['ShipAddr', 'BillAddr'] as $key) {
            $addr = $invoice[$key] ?? null;
            if (!is_array($addr)) {
                continue;
            }
            $postal = $addr['PostalCode'] ?? null;
            if (!is_string($postal) || $postal === '') {
                continue;
            }
            $country = self::resolveCountryFromAddr($addr);
            return [$country, $postal];
        }
        throw new PayloadException('Invoice has no usable ShipAddr or BillAddr with PostalCode');
    }

    /**
     * @param array<string, mixed> $addr
     */
    private static function resolveCountryFromAddr(array $addr): string
    {
        // Intuit sometimes ships ISO-2 in CountryCode, sometimes a label
        // ("USA", "United States") in Country. Coerce to ISO-2 US-or-throw.
        foreach (['CountryCode', 'Country'] as $key) {
            $raw = $addr[$key] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $upper = strtoupper(trim($raw));
            if ($upper === 'US' || $upper === 'USA' || $upper === 'UNITED STATES') {
                return 'US';
            }
            // Recognized but non-US — fail loudly so the caller can return 204.
            throw new PayloadException("Invoice address country '{$raw}' is not US");
        }
        // No country marker AT ALL — assume US (QBO often omits country for
        // US-default companies). This matches Intuit's own UI behavior.
        return 'US';
    }

    /**
     * @return array{0: string, 1: ?string} zip5, zip4
     */
    private function splitZip(string $raw): array
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) < 5) {
            throw new PayloadException("PostalCode '{$raw}' has fewer than 5 digits");
        }
        $zip5 = substr($digits, 0, 5);
        $zip4 = strlen($digits) >= 9 ? substr($digits, 5, 4) : null;
        return [$zip5, $zip4];
    }

    /**
     * @param array<string, mixed> $invoice
     * @return InvoiceLine[]
     */
    private function extractLines(array $invoice): array
    {
        $rawLines = $invoice['Line'] ?? null;
        if (!is_array($rawLines)) {
            throw new PayloadException('Invoice missing Line array');
        }
        $out = [];
        foreach ($rawLines as $idx => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $detailType = $raw['DetailType'] ?? null;
            if ($detailType !== 'SalesItemLineDetail') {
                continue; // skip subtotal / discount / group lines
            }
            $amount = $raw['Amount'] ?? null;
            if (!is_numeric($amount)) {
                throw new PayloadException("Line[{$idx}].Amount is not numeric");
            }
            $subtotal = self::numericToDecimalString($amount);
            if (str_starts_with($subtotal, '-')) {
                continue; // negative-amount lines are refunds; skip in v0.1
            }
            $lineNum = isset($raw['LineNum']) && is_numeric($raw['LineNum'])
                ? (int) $raw['LineNum']
                : count($out) + 1;
            $description = isset($raw['Description']) && is_string($raw['Description'])
                ? $raw['Description']
                : '';
            $out[] = new InvoiceLine($lineNum, $subtotal, $description);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $raw = $data[$key] ?? null;
        if (is_string($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return (string) $raw;
        }
        return '';
    }

    private static function numericToDecimalString(int|float|string $val): string
    {
        if (is_string($val)) {
            return $val;
        }
        if (is_int($val)) {
            return (string) $val;
        }
        // Avoid scientific notation for floats.
        $formatted = rtrim(rtrim(sprintf('%.6F', $val), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}
