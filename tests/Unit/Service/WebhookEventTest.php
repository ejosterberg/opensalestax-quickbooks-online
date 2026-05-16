<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Tests\Unit\Service;

use OpenSalesTax\QuickBooksOnline\Service\WebhookEvent;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    public function testParsesIntuitBatch(): void
    {
        $body = [
            'eventNotifications' => [
                [
                    'realmId' => '1234567890',
                    'dataChangeEvent' => [
                        'entities' => [
                            [
                                'name' => 'Invoice',
                                'id' => '145',
                                'operation' => 'Create',
                                'lastUpdated' => '2026-05-15T12:34:56.000-08:00',
                            ],
                            [
                                'name' => 'Customer',
                                'id' => '7',
                                'operation' => 'Update',
                                'lastUpdated' => '2026-05-15T12:34:56.000-08:00',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $events = WebhookEvent::listFromBody($body);
        $this->assertCount(2, $events);
        $this->assertSame('Invoice', $events[0]->entityName);
        $this->assertSame('145', $events[0]->entityId);
        $this->assertSame('Create', $events[0]->operation);
        $this->assertSame('1234567890', $events[0]->realmId);
        $this->assertSame('Customer', $events[1]->entityName);
    }

    public function testEmptyBodyReturnsEmpty(): void
    {
        $this->assertSame([], WebhookEvent::listFromBody([]));
        $this->assertSame([], WebhookEvent::listFromBody(['eventNotifications' => 'not-array']));
    }

    public function testIntegerIdCoerced(): void
    {
        $events = WebhookEvent::listFromBody([
            'eventNotifications' => [[
                'realmId' => 999,
                'dataChangeEvent' => ['entities' => [[
                    'name' => 'Invoice',
                    'id' => 145,
                    'operation' => 'Create',
                ]]],
            ]],
        ]);
        $this->assertCount(1, $events);
        $this->assertSame('145', $events[0]->entityId);
        $this->assertSame('999', $events[0]->realmId);
    }
}
