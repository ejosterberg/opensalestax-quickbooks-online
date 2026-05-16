<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Service;

/**
 * One entity-change event extracted from an Intuit webhook batch.
 *
 * Intuit's webhook body shape:
 *
 *   {
 *     "eventNotifications": [
 *       {
 *         "realmId": "1234567890",
 *         "dataChangeEvent": {
 *           "entities": [
 *             {"name": "Invoice", "id": "145", "operation": "Create",
 *              "lastUpdated": "2026-05-15T12:34:56.000-08:00"},
 *             ...
 *           ]
 *         }
 *       },
 *       ...
 *     ]
 *   }
 *
 * Each WebhookEvent here represents one entity-row inside one event-
 * notification (so a single webhook POST can produce many events).
 */
final class WebhookEvent
{
    public function __construct(
        public readonly string $realmId,
        public readonly string $entityName,
        public readonly string $entityId,
        public readonly string $operation,
        public readonly string $lastUpdated,
    ) {
    }

    /**
     * @param array<string, mixed> $body Decoded webhook JSON body.
     * @return list<self>
     */
    public static function listFromBody(array $body): array
    {
        $out = [];
        $notifications = $body['eventNotifications'] ?? null;
        if (!is_array($notifications)) {
            return $out;
        }
        foreach ($notifications as $n) {
            if (!is_array($n)) {
                continue;
            }
            $realmId = $n['realmId'] ?? null;
            if (!is_string($realmId) && !is_int($realmId)) {
                continue;
            }
            $realmId = (string) $realmId;
            $dataChange = $n['dataChangeEvent'] ?? null;
            if (!is_array($dataChange)) {
                continue;
            }
            $entities = $dataChange['entities'] ?? null;
            if (!is_array($entities)) {
                continue;
            }
            foreach ($entities as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $name = $e['name'] ?? null;
                $id = $e['id'] ?? null;
                $op = $e['operation'] ?? null;
                $lu = $e['lastUpdated'] ?? null;
                if (!is_string($name) || !is_string($op)) {
                    continue;
                }
                if (!is_string($id) && !is_int($id)) {
                    continue;
                }
                $out[] = new self(
                    realmId: $realmId,
                    entityName: $name,
                    entityId: (string) $id,
                    operation: $op,
                    lastUpdated: is_string($lu) ? $lu : '',
                );
            }
        }
        return $out;
    }
}
