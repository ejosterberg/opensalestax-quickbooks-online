<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Service;

use GuzzleHttp\Client as GuzzleClient;
use OpenSalesTax\Client as OstClient;
use OpenSalesTax\QuickBooksOnline\Config\Config;
use OpenSalesTax\QuickBooksOnline\Logging\StderrLogger;
use OpenSalesTax\QuickBooksOnline\Oauth\EncryptedFileTokenStore;
use OpenSalesTax\QuickBooksOnline\Oauth\OAuthFlow;
use OpenSalesTax\QuickBooksOnline\Oauth\TokenStore;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\InvoicePayloadBuilder;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\QboClient;
use OpenSalesTax\QuickBooksOnline\QuickBooksOnline\TaxApplier;
use OpenSalesTax\QuickBooksOnline\Sdk\EngineGateway;
use OpenSalesTax\QuickBooksOnline\Security\RateLimiter;
use OpenSalesTax\QuickBooksOnline\Security\ReplayCache;
use OpenSalesTax\QuickBooksOnline\Security\SignatureVerifier;
use OpenSalesTax\QuickBooksOnline\Security\UrlValidator;
use Psr\Log\LoggerInterface;

/**
 * Construct the application-level graph from a Config.
 *
 * Pure factory — no env reads here. The single entry point that touches
 * the process environment is Config::loadFromProcess(). Everything else
 * stays unit-testable.
 */
final class Bootstrap
{
    public static function tokenStore(Config $config): TokenStore
    {
        return new EncryptedFileTokenStore(
            path: $config->qboTokenStorePath(),
            key: $config->qboTokenEncryptionKey(),
        );
    }

    public static function webhookHandler(Config $config, ?LoggerInterface $logger = null): WebhookHandler
    {
        $logger ??= new StderrLogger();
        $processor = self::invoiceProcessor($config, $logger);

        return new WebhookHandler(
            signature: new SignatureVerifier($config->qboWebhookVerifierToken()),
            replayCache: new ReplayCache(ttlSeconds: $config->replayWindowSeconds()),
            rateLimiter: new RateLimiter(capacityPerMinute: $config->rateLimitPerMinute()),
            invoiceProcessor: $processor,
            logger: $logger,
            failHard: $config->failHard(),
        );
    }

    public static function oauthCallbackHandler(
        Config $config,
        ?LoggerInterface $logger = null,
    ): OAuthCallbackHandler {
        $logger ??= new StderrLogger();
        $oauth = self::oauthFlow($config);
        $stateFile = dirname($config->qboTokenStorePath()) . '/oauth-state.txt';
        return new OAuthCallbackHandler(
            oauth: $oauth,
            tokenStore: self::tokenStore($config),
            stateFilePath: $stateFile,
            logger: $logger,
        );
    }

    public static function oauthFlow(Config $config): OAuthFlow
    {
        return new OAuthFlow(
            clientId: $config->qboClientId(),
            clientSecret: $config->qboClientSecret(),
            redirectUri: $config->qboRedirectUri(),
            http: new GuzzleClient(),
        );
    }

    public static function invoiceProcessor(Config $config, LoggerInterface $logger): InvoiceProcessor
    {
        $urlValidator = new UrlValidator($config->allowPrivateNetworks());

        $ostClient = new OstClient(
            baseUrl: $config->engineUrl(),
            apiKey: $config->engineApiKey(),
            timeoutSeconds: $config->engineTimeoutSeconds(),
        );
        $engine = new EngineGateway(
            client: $ostClient,
            urlValidator: $urlValidator,
            engineUrl: $config->engineUrl(),
            logger: $logger,
        );

        $qbo = new QboClient(
            tokenStore: self::tokenStore($config),
            oauth: self::oauthFlow($config),
            environment: $config->qboEnvironment(),
            urlValidator: $urlValidator,
            http: new GuzzleClient(),
            logger: $logger,
            timeoutSeconds: $config->engineTimeoutSeconds(),
            tlsVerify: $config->tlsVerify(),
        );

        return new InvoiceProcessor(
            qbo: $qbo,
            payloadBuilder: new InvoicePayloadBuilder(),
            engine: $engine,
            taxApplier: new TaxApplier(),
            logger: $logger,
            failHard: $config->failHard(),
        );
    }
}
