<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit\Commands;

use App\Models\Site;
use App\PublicVisit\Channel;
use App\VisitorWorkflow\Models\VisitorType;

/**
 * Everything a visitor submits to sign in. The site comes from the channel (kiosk device or
 * QR token), never from the visitor. Nothing here is a database id the visitor chose: the host
 * is an opaque option token from the host directory.
 */
final class ArrivalRequest
{
    /**
     * @param  array{first_name?: mixed, name?: mixed, email?: mixed, phone?: mixed, company?: mixed}  $details
     * @param  array<string, mixed>  $answers  field key => value or UploadedFile
     * @param  list<int>  $acceptedPolicyVersionIds
     */
    public function __construct(
        public readonly Site $site,
        public readonly Channel $channel,
        public readonly string $submissionKey,
        public readonly VisitorType $visitorType,
        public readonly array $details,
        public readonly ?string $hostToken,
        public readonly array $answers,
        public readonly array $acceptedPolicyVersionIds,
        /** Set by the server from the enrolled device, never by the visitor. */
        public readonly ?int $kioskId = null,
    ) {}
}
