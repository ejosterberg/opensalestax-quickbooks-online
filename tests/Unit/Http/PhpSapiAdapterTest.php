<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Http;

use OpenSalesTax\QuickBooksOnline\Http\PhpSapiAdapter;
use PHPUnit\Framework\TestCase;

final class PhpSapiAdapterTest extends TestCase
{
    public function testBuildRequestExtractsPathAndQuery(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/oauth/callback?code=ABC&state=XYZ&realmId=999',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_INTUIT_SIGNATURE' => 'sig-value',
        ];
        $req = PhpSapiAdapter::buildRequest($server, '');
        $this->assertSame('GET', $req->method);
        $this->assertSame('/oauth/callback', $req->path);
        $this->assertSame('ABC', $req->query['code']);
        $this->assertSame('XYZ', $req->query['state']);
        $this->assertSame('999', $req->query['realmId']);
        $this->assertSame('10.0.0.1', $req->sourceIp);
        $this->assertSame('sig-value', $req->header('intuit-signature'));
        $this->assertSame('sig-value', $req->header('Intuit-Signature'));
    }

    public function testDefaultsWhenServerIncomplete(): void
    {
        $req = PhpSapiAdapter::buildRequest([], '{}');
        $this->assertSame('GET', $req->method);
        $this->assertSame('/', $req->path);
        $this->assertSame('unknown', $req->sourceIp);
        $this->assertSame('{}', $req->body);
    }

    public function testContentTypeHeaderIncluded(): void
    {
        $req = PhpSapiAdapter::buildRequest([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/x',
            'CONTENT_TYPE' => 'application/json',
        ], '');
        $this->assertSame('application/json', $req->header('Content-Type'));
    }
}
