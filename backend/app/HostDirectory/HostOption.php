<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\HostDirectory;

/**
 * What a visitor sees for one search result: a name and an opaque token to submit.
 */
final class HostOption
{
    public function __construct(
        public readonly string $token,
        public readonly string $displayName,
    ) {}
}
