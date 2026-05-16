<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\QuickBooksOnline;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use OpenSalesTax\QuickBooksOnline\Oauth\OAuthFlow;
use OpenSalesTax\QuickBooksOnline\Oauth\TokenSet;
use OpenSalesTax\QuickBooksOnline\Oauth\TokenStore;
use OpenSalesTax\QuickBooksOnline\Security\UrlValidator;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around the QuickBooks Online v3 REST API.
 *
 * Why not use Intuit's `quickbooks/v3-php-sdk` directly?
 *
 *   We DO depend on it (composer.json) so merchants who want to invoke
 *   other parts of the QBO API (Customers, Estimates, etc.) from their
 *   own code have it available alongside the sidecar. But for the
 *   sidecar's OWN hot path — fetch invoice, update invoice — we use
 *   Guzzle directly because:
 *     1. The SDK's `DataService` uses internal curl that is awkward to
 *        mock in PHPUnit (no Guzzle handler stack to swap in).
 *     2. The two endpoints we hit (`GET /v3/company/{id}/invoice/{id}`,
 *        `POST /v3/company/{id}/invoice` with sparse=true) are stable
 *        across SDK versions — we don't gain much by routing through
 *        the SDK.
 *     3. Refresh-on-the-fly works the same with our `OAuthFlow` wrapper
 *        as with the SDK's `OAuth2LoginHelper::refreshToken()`.
 *
 *   This decision is recorded informally in this docblock and may be
 *   revisited in v0.2 if the SDK gains a Guzzle-based transport.
 */
final class QboClient
{
    public function __construct(
        private readonly TokenStore $tokenStore,
        private readonly OAuthFlow $oauth,
        /** @var 'sandbox'|'production' */
        private readonly string $environment,
        private readonly UrlValidator $urlValidator,
        private readonly GuzzleClient $http,
        private readonly LoggerInterface $logger,
        private readonly float $timeoutSeconds = 10.0,
        private readonly bool $tlsVerify = true,
    ) {
    }

    public function baseUrl(): string
    {
        return $this->environment === 'sandbox'
            ? 'https://sandbox-quickbooks.api.intuit.com'
            : 'https://quickbooks.api.intuit.com';
    }

    /**
     * @return array<string, mixed> the decoded `Invoice` resource (the inner
     *   object — i.e. without the outer `{"Invoice": ...}` envelope).
     *
     * @throws QboClientException on any failure.
     */
    public function fetchInvoice(string $realmId, string $invoiceId): array
    {
        $tokens = $this->ensureToken($realmId);
        $url = sprintf(
            '%s/v3/company/%s/invoice/%s',
            $this->baseUrl(),
            rawurlencode($realmId),
            rawurlencode($invoiceId),
        );
        $this->urlValidator->validate($url);

        $response = $this->call('GET', $url, $tokens, null);
        $body = $this->decodeBody($response);
        $invoice = $body['Invoice'] ?? null;
        if (!is_array($invoice)) {
            throw new QboClientException("QBO fetch invoice {$invoiceId} returned no Invoice object");
        }
        /** @var array<string, mixed> $invoice */
        return $invoice;
    }

    /**
     * Update (sparse) an invoice with a new TxnTaxDetail. The full invoice
     * object isn't sent — only the fields needed for QBO to identify the
     * row plus the tax fields.
     *
     * @param array<string, mixed> $sparseUpdate The sparse update body
     *   (must include `Id`, `SyncToken`, and `sparse: true`).
     */
    public function updateInvoiceSparse(string $realmId, array $sparseUpdate): void
    {
        $tokens = $this->ensureToken($realmId);
        $url = sprintf(
            '%s/v3/company/%s/invoice',
            $this->baseUrl(),
            rawurlencode($realmId),
        );
        $this->urlValidator->validate($url);

        $response = $this->call('POST', $url, $tokens, $sparseUpdate);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('qbo invoice update failed', [
                'realm_id' => $realmId,
                'invoice_id' => $sparseUpdate['Id'] ?? null,
                'http_status' => $status,
            ]);
            throw new QboClientException("QBO invoice update returned HTTP {$status}");
        }
    }

    /**
     * Get a refreshed token if the current access token is near expiry.
     */
    private function ensureToken(string $realmId): TokenSet
    {
        $tokens = $this->tokenStore->load($realmId);
        if ($tokens === null) {
            throw new QboClientException("No OAuth tokens stored for realm {$realmId}");
        }
        if ($tokens->isAccessTokenExpired()) {
            $this->logger->info('qbo refreshing access token', ['realm_id' => $realmId]);
            $tokens = $this->oauth->refresh($tokens);
            $this->tokenStore->save($tokens);
        }
        return $tokens;
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     */
    private function call(
        string $method,
        string $url,
        TokenSet $tokens,
        ?array $jsonBody,
    ): \Psr\Http\Message\ResponseInterface {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $tokens->accessToken,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
            'timeout' => $this->timeoutSeconds,
            'connect_timeout' => $this->timeoutSeconds,
            'verify' => $this->tlsVerify,
        ];
        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
            $options['headers']['Content-Type'] = 'application/json';
        }
        try {
            return $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new QboClientException("QBO API transport error: " . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(\Psr\Http\Message\ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new QboClientException("QBO API returned HTTP {$status}: " . substr($raw, 0, 200));
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new QboClientException('QBO API returned non-JSON body: ' . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new QboClientException('QBO API returned non-object JSON');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
