<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\Models\Visitor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Privacy-safe recognition of returning visitors (brief §15).
 *
 * - Matching needs an exact email address or mobile number AND the exact last name; there is
 *   no search, no partial match and no hint about which part did not match.
 * - The screen only ever receives a masked summary (first name, "p•••@e•••.org", "•••• 678")
 *   and which fields have saved values; the stored values are merged in on the server at
 *   submit. Knowing someone's email and surname therefore never reveals their phone number.
 * - The visitor must confirm before saved details are used.
 * - On a personal phone the visitor may opt in to "remember me": a long-lived encrypted token
 *   in a cookie on that phone only.
 */
class ReturningVisitors
{
    public const PURPOSE_CONFIRMED = 'confirmed';

    public const PURPOSE_REMEMBER = 'remember';

    public const CONFIRMED_TTL_MINUTES = 30;

    public const REMEMBER_TTL_DAYS = 180;

    public const REMEMBER_COOKIE = 'vp_remember';

    /** Fields that can be filled from a saved record. Names are always typed or confirmed. */
    public const SAVED_FIELDS = ['email', 'phone', 'company'];

    public function find(string $identifier, string $lastName): ?Visitor
    {
        $identifier = trim($identifier);
        $last = mb_strtolower(trim($lastName));

        if ($identifier === '' || $last === '' || mb_strlen($identifier) > 191) {
            return null;
        }

        $candidates = Visitor::query()->whereRaw('LOWER(name) = ?', [$last]);

        if (str_contains($identifier, '@')) {
            return $candidates->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)])->latest('updated_at')->first();
        }

        $digits = self::digits($identifier);

        if (strlen($digits) < 8) {
            return null;
        }

        return $candidates->whereNotNull('phone')->latest('updated_at')->limit(50)->get()
            ->first(fn (Visitor $visitor): bool => self::samePhone($digits, self::digits((string) $visitor->phone)));
    }

    public function token(Visitor $visitor, string $purpose = self::PURPOSE_CONFIRMED): string
    {
        $expires = $purpose === self::PURPOSE_REMEMBER
            ? now()->addDays(self::REMEMBER_TTL_DAYS)
            : now()->addMinutes(self::CONFIRMED_TTL_MINUTES);

        return Crypt::encryptString(json_encode(['p' => $visitor->getKey(), 'u' => $purpose, 'e' => $expires->timestamp]));
    }

    public function resolve(?string $token): ?Visitor
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload['e'] ?? 0) < now()->timestamp
            || ! in_array($payload['u'] ?? null, [self::PURPOSE_CONFIRMED, self::PURPOSE_REMEMBER], true)) {
            return null;
        }

        return Visitor::query()->find($payload['p'] ?? 0);
    }

    /**
     * What the screen may show to confirm "is this you?".
     *
     * @return array{first_name: string, last_name: string, email: ?string, phone: ?string, company: bool}
     */
    public function summary(Visitor $visitor): array
    {
        return [
            'first_name' => (string) $visitor->first_name,
            'last_name' => (string) $visitor->name,
            'email' => self::maskEmail($visitor->email),
            'phone' => self::maskPhone($visitor->phone),
            'company' => filled($visitor->company),
        ];
    }

    /**
     * Which of the saved fields hold a value (so the screen can say "saved" instead of asking).
     *
     * @return list<string>
     */
    public function savedFields(Visitor $visitor): array
    {
        return array_values(array_filter(self::SAVED_FIELDS, fn (string $field): bool => filled($visitor->{$field})));
    }

    /**
     * Details for validation: what the visitor typed, with saved values for anything left empty.
     * First and last name always come from the saved record unless retyped.
     *
     * @param  array<string, mixed>  $typed
     * @return array<string, mixed>
     */
    public function merge(Visitor $visitor, array $typed): array
    {
        $merged = $typed;

        foreach (['first_name', 'name', ...self::SAVED_FIELDS] as $field) {
            $value = $typed[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $merged[$field] = $visitor->{$field};
            }
        }

        return $merged;
    }

    public static function maskEmail(?string $email): ?string
    {
        if (blank($email) || ! str_contains((string) $email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', (string) $email, 2);
        $dot = strrpos($domain, '.');
        $tld = $dot !== false ? substr($domain, $dot) : '';

        return mb_substr($local, 0, 1).'•••@'.mb_substr($domain, 0, 1).'•••'.$tld;
    }

    public static function maskPhone(?string $phone): ?string
    {
        $digits = self::digits((string) $phone);

        return strlen($digits) >= 6 ? '•••• '.substr($digits, -3) : null;
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    /**
     * Same number written differently: "0412 345 678", "+61 412 345 678" and "61412345678"
     * compare equal by their last nine digits; both must have at least eight.
     */
    private static function samePhone(string $a, string $b): bool
    {
        return strlen($a) >= 8 && strlen($b) >= 8 && substr($a, -9) === substr($b, -9);
    }
}
