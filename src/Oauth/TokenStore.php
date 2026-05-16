<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\QuickBooksOnline\Oauth;

interface TokenStore
{
    /**
     * Persist the token set, replacing any existing entry for the realm.
     *
     * @throws TokenStoreException on any IO / encryption failure.
     */
    public function save(TokenSet $tokens): void;

    /**
     * Load the token set for a realm, or null if none exists.
     *
     * @throws TokenStoreException on any IO / decryption failure.
     */
    public function load(string $realmId): ?TokenSet;

    /**
     * @return list<string> realm ids that have stored tokens.
     */
    public function listRealms(): array;
}
