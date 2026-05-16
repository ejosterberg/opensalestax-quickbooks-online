<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Service;

use OpenSalesTax\QuickBooksOnline\Http\Request;
use OpenSalesTax\QuickBooksOnline\Http\Response;

/**
 * Top-level HTTP router. The sidecar has three paths and any other
 * request is a 404:
 *
 *   GET  /health                       → WebhookHandler::handle (returns health)
 *   POST /webhooks/quickbooks-online   → WebhookHandler::handle
 *   GET  /oauth/callback               → OAuthCallbackHandler::handle
 */
final class Router
{
    public function __construct(
        private readonly WebhookHandler $webhookHandler,
        private readonly OAuthCallbackHandler $oauthCallbackHandler,
    ) {
    }

    public function handle(Request $req): Response
    {
        if ($req->method === 'GET' && $req->path === '/oauth/callback') {
            return $this->oauthCallbackHandler->handle($req);
        }
        // Everything else (health probe, webhook, 404) goes to WebhookHandler.
        return $this->webhookHandler->handle($req);
    }
}
