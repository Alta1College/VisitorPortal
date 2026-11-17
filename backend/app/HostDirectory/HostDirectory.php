<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory;

use App\Models\Site;
use App\Models\User;

/**
 * Where visitors find the person they are visiting. Two implementations are expected (brief §18):
 * local users now, a Microsoft Entra sync later. Both resolve to local users, because upstream
 * visits require a local host.
 */
interface HostDirectory
{
    /**
     * Hosts at a site whose name starts with the query. Returns display names and opaque
     * option tokens only; never ids, emails or departments.
     *
     * @return list<HostOption>
     */
    public function search(Site $site, string $query): array;

    /**
     * The host for an option token issued by search(), or null if the token is invalid,
     * expired, for another site, or the host is no longer available.
     */
    public function resolve(Site $site, string $optionToken): ?User;
}
