<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and redeems opaque attendance tokens. A token is 40 random characters (~238 bits);
 * only its SHA-256 is stored, so a database leak does not yield usable sign-out links.
 */
class AttendanceTokens
{
    public const PURPOSE_DEPARTURE = 'departure';

    public const DEPARTURE_TTL_HOURS = 36;

    public function issue(Visit $visit, Visitor $visitor, string $purpose = self::PURPOSE_DEPARTURE): string
    {
        $token = Str::random(40);

        DB::table('attendance_tokens')->insert([
            'token_hash' => self::hash($token),
            'purpose' => $purpose,
            'visit_id' => $visit->getKey(),
            'visitor_id' => $visitor->getKey(),
            'expires_at' => now()->addHours(self::DEPARTURE_TTL_HOURS),
            'used_at' => null,
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * The unused, unexpired token row for a presented token, or null. Constant work for
     * well-formed and malformed input alike, so responses do not reveal which part failed.
     */
    public function find(string $token, string $purpose = self::PURPOSE_DEPARTURE): ?object
    {
        $row = DB::table('attendance_tokens')
            ->where('token_hash', self::hash($token))
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        return $row ?: null;
    }

    /**
     * Mark every open token of an attendance used (after sign-out, or when staff close the visit).
     */
    public function invalidateFor(int $visitId, int $visitorId): void
    {
        DB::table('attendance_tokens')
            ->where('visit_id', $visitId)
            ->where('visitor_id', $visitorId)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
