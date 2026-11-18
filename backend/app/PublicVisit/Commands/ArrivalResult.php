<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Commands;

/**
 * What the public screen may show after sign-in: the visitor's own first name, who they are
 * visiting, and a departure token for self sign-out. No ids, no other visitors' data.
 */
final class ArrivalResult
{
    public function __construct(
        public readonly string $departureToken,
        public readonly string $visitorFirstName,
        public readonly string $hostDisplayName,
        public readonly bool $replayed,
        /** @internal for server-side follow-up (badge, notification); never sent to the client */
        public readonly int $visitId,
        /** @internal */
        public readonly int $visitorId,
        /** A badge was sent to a label printer for this sign-in. */
        public readonly bool $badgeQueued = false,
    ) {}
}
