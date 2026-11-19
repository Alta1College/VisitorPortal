<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Kiosk;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enrolment and identification of kiosk devices.
 *
 * An administrator issues a one-time code (8 characters from an unambiguous alphabet, 24 h);
 * typing it on the device exchanges it for a 64-character device secret kept in an encrypted,
 * HttpOnly cookie. Only hashes are stored. A new code or a revocation invalidates the old device.
 */
class KioskDevices
{
    public const COOKIE = 'vp_kiosk';

    public const COOKIE_MINUTES = 60 * 24 * 400;

    public const CODE_TTL_HOURS = 24;

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Issue a new enrolment code. Any previously enrolled device for this kiosk stops working.
     *
     * @return string the code to show the administrator once
     */
    public function issueEnrolmentCode(Kiosk $kiosk): string
    {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        // Reload first: on a stale instance, clearing an already-cleared-in-memory device token
        // would look unchanged and never be saved, leaving the old device connected.
        $kiosk->refresh();

        $kiosk->forceFill([
            'enrolment_code_hash' => self::hash(self::normaliseCode($code)),
            'enrolment_code_expires_at' => now()->addHours(self::CODE_TTL_HOURS),
            'device_token_hash' => null,
            'enrolled_at' => null,
            'revoked_at' => null,
        ])->save();

        $this->audit->record('kiosk.enrolment_code_issued', subject: $kiosk, site: $kiosk->site_id);

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * Exchange a code for a device secret. Returns null for any invalid, used or expired code.
     *
     * @return array{0: Kiosk, 1: string}|null kiosk and the device secret for the cookie
     */
    public function enrol(string $code): ?array
    {
        $normalised = self::normaliseCode($code);

        if (strlen($normalised) !== 8) {
            $this->audit->record('kiosk.enrolment_failed', result: AuditEvent::RESULT_DENIED);

            return null;
        }

        return DB::transaction(function () use ($normalised): ?array {
            $kiosk = Kiosk::query()
                ->where('enrolment_code_hash', self::hash($normalised))
                ->where('enrolment_code_expires_at', '>', now())
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($kiosk === null) {
                $this->audit->record('kiosk.enrolment_failed', result: AuditEvent::RESULT_DENIED);

                return null;
            }

            $secret = Str::random(64);

            $kiosk->forceFill([
                'enrolment_code_hash' => null,
                'enrolment_code_expires_at' => null,
                'device_token_hash' => self::hash($secret),
                'enrolled_at' => now(),
                'last_seen_at' => now(),
            ])->save();

            $this->audit->record('kiosk.enrolled', subject: $kiosk, site: $kiosk->site_id);

            return [$kiosk, $secret];
        });
    }

    /**
     * The active kiosk this request's device cookie belongs to, or null.
     */
    public function fromRequest(Request $request): ?Kiosk
    {
        $secret = $request->cookie(self::COOKIE);

        if (! is_string($secret) || strlen($secret) !== 64) {
            return null;
        }

        $kiosk = Kiosk::query()->active()->with('site')->where('device_token_hash', self::hash($secret))->first();

        if ($kiosk !== null && ($kiosk->last_seen_at === null || $kiosk->last_seen_at->lt(now()->subMinutes(5)))) {
            $kiosk->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $kiosk;
    }

    public function revoke(Kiosk $kiosk): void
    {
        $kiosk->refresh();

        $kiosk->forceFill([
            'revoked_at' => now(),
            'device_token_hash' => null,
            'enrolment_code_hash' => null,
            'enrolment_code_expires_at' => null,
        ])->save();

        $this->audit->record('kiosk.revoked', subject: $kiosk, site: $kiosk->site_id);
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
