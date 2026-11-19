<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

use App\Models\Visitor;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\WorkflowDefinition;
use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Recognition of returning visitors (brief §15). Every sign-in keeps the visitor record, and the
 * answers to questions marked "Remember for returning visitors", so the next visit can be
 * pre-filled.
 *
 * - Matching needs an exact email address or mobile number: no search, no partial match.
 * - The visitor must confirm ("That's me") before saved details are used.
 * - On the kiosk (on site) the saved values and photo are shown and pre-filled. On phones (the
 *   QR page is internet-reachable) only a masked summary is shown and saved values are merged
 *   in on the server at submit, so knowing someone's email address never reveals their details
 *   or photo there.
 */
class ReturningVisitors
{
    public const PURPOSE_CONFIRMED = 'confirmed';

    public const CONFIRMED_TTL_MINUTES = 30;

    public const PHOTO_TTL_MINUTES = 30;

    /** Detail fields that can be filled from a saved record. */
    public const SAVED_FIELDS = ['first_name', 'name', 'email', 'phone', 'company'];

    public function find(string $identifier): ?Visitor
    {
        $identifier = trim($identifier);

        if ($identifier === '' || mb_strlen($identifier) > 191) {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return Visitor::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)])->latest('updated_at')->first();
        }

        $digits = self::digits($identifier);

        if (strlen($digits) < 8) {
            return null;
        }

        // Narrow in SQL by the last digit, then compare the normalised numbers exactly.
        return Visitor::query()->whereNotNull('phone')->where('phone', 'like', '%'.substr($digits, -1))
            ->latest('updated_at')->limit(1000)->get()
            ->first(fn (Visitor $visitor): bool => self::samePhone($digits, self::digits((string) $visitor->phone)));
    }

    public function token(Visitor $visitor, string $purpose = self::PURPOSE_CONFIRMED): string
    {
        $expires = now()->addMinutes(self::CONFIRMED_TTL_MINUTES);

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
            || ! in_array($payload['u'] ?? null, [self::PURPOSE_CONFIRMED], true)) {
            return null;
        }

        return Visitor::query()->find($payload['p'] ?? 0);
    }

    /**
     * What the screen may show to confirm "is this you?": the saved values on a kiosk, a masked
     * summary on a phone.
     *
     * @return array{first_name: string, last_name: string, email: ?string, phone: ?string, company: ?string, masked: bool}
     */
    public function summary(Visitor $visitor, bool $full): array
    {
        return [
            'first_name' => (string) $visitor->first_name,
            'last_name' => $full ? (string) $visitor->name : mb_substr((string) $visitor->name, 0, 1).'.',
            'email' => $full ? $visitor->email : self::maskEmail($visitor->email),
            'phone' => $full ? $visitor->phone : self::maskPhone($visitor->phone),
            'company' => $full ? $visitor->company : (filled($visitor->company) ? __('Firma gespeichert') : null),
            'masked' => ! $full,
        ];
    }

    /**
     * Which detail fields hold a saved value (so the screen can say "saved" instead of asking).
     *
     * @return list<string>
     */
    public function savedFields(Visitor $visitor): array
    {
        return array_values(array_filter(self::SAVED_FIELDS, fn (string $field): bool => filled($visitor->{$field})));
    }

    /**
     * Details for validation: what the visitor typed, with saved values for anything left empty.
     *
     * @param  array<string, mixed>  $typed
     * @return array<string, mixed>
     */
    public function merge(Visitor $visitor, array $typed): array
    {
        $merged = $typed;

        foreach (self::SAVED_FIELDS as $field) {
            $value = $typed[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $merged[$field] = $visitor->{$field};
            }
        }

        return $merged;
    }

    /**
     * The visitor's latest answer to each remembered question in this workflow. File answers
     * count only while the evidence file still exists (it is purged after the retention period).
     *
     * @return array<string, VisitResponse> field key => response
     */
    public function savedAnswers(Visitor $visitor, WorkflowDefinition $definition): array
    {
        $saved = [];

        foreach ($definition->fields as $resolved) {
            $field = $resolved->field;

            if (! $field->remembersAnswer()) {
                continue;
            }

            $response = VisitResponse::query()
                ->where('visitor_id', $visitor->getKey())
                ->where('form_field_id', $field->getKey())
                ->latest('id')
                ->first();

            if ($response === null) {
                continue;
            }

            $usable = in_array($field->type, FormField::FILE_TYPES, true)
                ? $response->file_path !== null && Storage::disk(WorkflowRecorder::EVIDENCE_DISK)->exists($response->file_path)
                : filled($response->value);

            if ($usable) {
                $saved[$field->key] = $response;
            }
        }

        return $saved;
    }

    /**
     * Answers for validation: what the visitor gave, with saved answers for remembered questions
     * left empty. A saved file is copied, so this visit keeps its own evidence file.
     *
     * @param  array<string, mixed>  $typed
     * @return array<string, mixed>
     */
    public function mergeAnswers(Visitor $visitor, WorkflowDefinition $definition, array $typed): array
    {
        $merged = $typed;

        foreach ($this->savedAnswers($visitor, $definition) as $key => $response) {
            $value = $typed[$key] ?? null;

            if ($value !== null && $value !== '') {
                continue;
            }

            $merged[$key] = $response->file_path !== null ? $this->fileCopy($response) : $response->plainValue();
        }

        return $merged;
    }

    /**
     * The visitor's latest remembered photo, for the kiosk's "is this you?" card.
     */
    public function latestPhoto(Visitor $visitor): ?string
    {
        $disk = Storage::disk(WorkflowRecorder::EVIDENCE_DISK);

        return VisitResponse::query()
            ->where('visitor_id', $visitor->getKey())
            ->where('field_type', FormField::TYPE_PHOTO)
            ->whereNotNull('file_path')
            ->whereHas('field', fn ($q) => $q->where('remember_for_returning', true))
            ->latest('id')
            ->limit(5)
            ->pluck('file_path')
            ->first(fn (string $path): bool => $disk->exists($path));
    }

    /**
     * Short-lived, site-bound token for showing a saved photo on that site's kiosks.
     */
    public function photoToken(string $path, int $siteId): string
    {
        return Crypt::encryptString(json_encode(['f' => $path, 's' => $siteId, 'e' => now()->addMinutes(self::PHOTO_TTL_MINUTES)->timestamp]));
    }

    public function resolvePhotoToken(string $token, int $siteId): ?string
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        $path = is_array($payload) ? ($payload['f'] ?? null) : null;

        if (! is_string($path) || ($payload['s'] ?? null) !== $siteId || ($payload['e'] ?? 0) < now()->timestamp
            || ! str_starts_with($path, WorkflowRecorder::EVIDENCE_DIRECTORY.'/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
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

    private function fileCopy(VisitResponse $response): ?UploadedFile
    {
        $disk = Storage::disk(WorkflowRecorder::EVIDENCE_DISK);
        $contents = $disk->get($response->file_path);

        if ($contents === null) {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'saved');
        file_put_contents($path, $contents);

        return new UploadedFile($path, basename($response->file_path), $disk->mimeType($response->file_path) ?: null, null, true);
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
