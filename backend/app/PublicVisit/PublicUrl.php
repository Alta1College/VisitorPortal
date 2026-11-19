<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

/**
 * Absolute addresses for links visitors open on their own devices (QR codes, emailed sign-out
 * links). Uses the "Public address" setting when set, otherwise the address of the current
 * request (or APP_URL on the console and in queued jobs).
 */
final class PublicUrl
{
    public static function to(string $path): string
    {
        $base = config('organisation.public_url')
            ?: (app()->runningInConsole() || ! app()->bound('request') ? config('app.url') : request()->root());

        return rtrim((string) $base, '/').'/'.ltrim($path, '/');
    }
}
