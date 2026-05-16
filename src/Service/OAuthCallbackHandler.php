<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Service;

use OpenSalesTax\QuickBooksOnline\Http\Request;
use OpenSalesTax\QuickBooksOnline\Http\Response;
use OpenSalesTax\QuickBooksOnline\Oauth\OAuthException;
use OpenSalesTax\QuickBooksOnline\Oauth\OAuthFlow;
use OpenSalesTax\QuickBooksOnline\Oauth\TokenStore;
use Psr\Log\LoggerInterface;

/**
 * Handle the inbound `GET /oauth/callback` from Intuit at the end of the
 * authorization-code flow.
 *
 * Validates:
 *   - the `state` query param matches what we stashed before redirecting
 *     (CSRF defense)
 *   - we have both `code` and `realmId` query params
 *
 * Then exchanges the code for tokens and saves them encrypted.
 *
 * The state store is a tiny single-key file (default
 * `./var/oauth-state.txt`). It's overwritten on each new oauth:setup run.
 */
final class OAuthCallbackHandler
{
    public function __construct(
        private readonly OAuthFlow $oauth,
        private readonly TokenStore $tokenStore,
        private readonly string $stateFilePath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Request $req): Response
    {
        if ($req->method !== 'GET' || $req->path !== '/oauth/callback') {
            return Response::plain(404, 'not found');
        }
        $code = $req->query['code'] ?? null;
        $realmId = $req->query['realmId'] ?? null;
        $state = $req->query['state'] ?? null;
        if ($code === null || $realmId === null || $state === null) {
            return Response::plain(400, 'missing code, realmId, or state');
        }

        $stashed = $this->readState();
        if ($stashed === null || !hash_equals($stashed, $state)) {
            $this->logger->warning('oauth state mismatch (possible CSRF)', []);
            return Response::plain(400, 'state mismatch');
        }

        try {
            $tokens = $this->oauth->exchangeCode($code, $realmId);
            $this->tokenStore->save($tokens);
        } catch (OAuthException $e) {
            $this->logger->error('oauth code exchange failed', ['reason' => $e->getMessage()]);
            return Response::plain(502, 'oauth exchange failed');
        }

        // One-shot use: clear the state file.
        @unlink($this->stateFilePath);

        return Response::json(200, [
            'status' => 'ok',
            'realm_id' => $realmId,
            'message' => 'Authorization complete. You can close this tab.',
        ]);
    }

    /**
     * Persist a freshly-generated state token for the in-progress OAuth
     * dance. Caller (oauth:setup CLI) generates the token and calls this
     * before opening the browser.
     */
    public function writeState(string $state): void
    {
        $dir = dirname($this->stateFilePath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0o700, true);
        }
        $tmp = $this->stateFilePath . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $state, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write OAuth state to {$this->stateFilePath}");
        }
        @chmod($tmp, 0o600);
        if (!@rename($tmp, $this->stateFilePath)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot atomically replace OAuth state at {$this->stateFilePath}");
        }
    }

    private function readState(): ?string
    {
        if (!is_file($this->stateFilePath)) {
            return null;
        }
        $raw = @file_get_contents($this->stateFilePath);
        if ($raw === false || $raw === '') {
            return null;
        }
        return trim($raw);
    }
}
