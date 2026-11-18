<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

use App\Models\Site;
use App\Models\Visit;
use App\Models\Visitor;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * What goes on one badge. Only what a badge should show: no contact details, no sensitive
 * answers (questions marked "show on badge" that are not sensitive), and the visit's own photo.
 */
final class BadgeContent
{
    /**
     * @param  array<string, string>  $fields  label => value
     */
    public function __construct(
        public readonly string $siteName,
        public readonly ?string $logo,
        public readonly string $typeName,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $company,
        public readonly ?string $hostName,
        public readonly string $date,
        public readonly string $time,
        public readonly ?string $photo,
        public readonly array $fields,
        public readonly string $footer,
    ) {}

    public static function forAttendance(Visit $visit, Visitor $visitor): self
    {
        $site = Site::query()->findOrFail($visit->site_id);
        $attendance = DB::table('visit_visitor')->where('visit_id', $visit->getKey())->where('visitor_id', $visitor->getKey())->first();
        $type = $attendance?->visitor_type_id ? VisitorType::query()->find($attendance->visitor_type_id) : null;
        $signedIn = Carbon::parse($attendance?->checked_in_at ?? now())->timezone(self::timezone($site));
        $host = $visit->host;

        $responses = VisitResponse::query()
            ->where('visit_id', $visit->getKey())
            ->where('visitor_id', $visitor->getKey())
            ->with('field')
            ->orderBy('id')
            ->get();

        $photo = $responses->first(fn (VisitResponse $r): bool => $r->field_type === FormField::TYPE_PHOTO && $r->file_path !== null);
        $fields = [];

        foreach ($responses as $response) {
            $field = $response->field;

            if ($field === null || ! $field->show_on_badge || $field->is_sensitive || in_array($field->type, FormField::FILE_TYPES, true) || blank($response->value)) {
                continue;
            }

            $fields[$field->label] = $field->displayAnswer((string) $response->plainValue());
        }

        return new self(
            siteName: (string) $site->name,
            logo: self::logo($site),
            typeName: $type?->badge_label ?: ($type?->name ?? __('Besucher')),
            firstName: (string) $visitor->first_name,
            lastName: (string) $visitor->name,
            company: filled($visitor->company) ? (string) $visitor->company : null,
            hostName: $host ? trim($host->first_name.' '.$host->name) : null,
            date: $signedIn->translatedFormat('j M Y'),
            time: $signedIn->format('H:i'),
            photo: self::photo($visitor, $photo?->file_path),
            fields: $fields,
            footer: self::footer($site),
        );
    }

    /**
     * A made-up visitor for test prints and previews.
     */
    public static function sample(Site $site): self
    {
        $now = now(self::timezone($site));

        return new self(
            siteName: (string) $site->name,
            logo: self::logo($site),
            typeName: __('Besucher'),
            firstName: 'Alex',
            lastName: __('Mustermann'),
            company: __('Musterfirma'),
            hostName: __('Empfang'),
            date: $now->translatedFormat('j M Y'),
            time: $now->format('H:i'),
            photo: null,
            fields: [],
            footer: self::footer($site),
        );
    }

    /**
     * The photo taken at this visit, otherwise the visitor's most recent photo from an earlier
     * visit (until evidence retention removes it). Null prints a "No photo" box.
     */
    private static function photo(Visitor $visitor, ?string $thisVisit): ?string
    {
        $disk = Storage::disk(WorkflowRecorder::EVIDENCE_DISK);

        $path = $thisVisit !== null && $disk->exists($thisVisit) ? $thisVisit : VisitResponse::query()
            ->where('visitor_id', $visitor->getKey())
            ->where('field_type', FormField::TYPE_PHOTO)
            ->whereNotNull('file_path')
            ->latest('id')
            ->limit(5)
            ->pluck('file_path')
            ->first(fn (string $candidate): bool => $disk->exists($candidate));

        return $path !== null ? self::fileDataUri($disk, $path) : null;
    }

    private static function footer(Site $site): string
    {
        return app(SettingsRepository::class)->forSite($site)->get('organisation.visitor_experience.badge_footer')
            ?: __('Bitte tragen Sie diesen Ausweis sichtbar und geben Sie ihn beim Gehen ab.');
    }

    private static function logo(Site $site): ?string
    {
        $settings = app(SettingsRepository::class)->forSite($site);
        // The black badge logo (for thermal labels), otherwise the normal logo.
        $path = $settings->get('branding.badge_logo') ?: ($settings->get('branding.logo_light') ?: config('branding.logo_light'));

        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }

        if (str_starts_with($path, 'storage/')) {
            return self::fileDataUri(Storage::disk('public'), substr($path, strlen('storage/')));
        }

        $file = public_path($path);

        return is_file($file) ? self::dataUri((string) file_get_contents($file), $file) : null;
    }

    private static function fileDataUri($disk, string $path): ?string
    {
        return $disk->exists($path) ? self::dataUri((string) $disk->get($path), $path) : null;
    }

    private static function dataUri(string $contents, string $name): ?string
    {
        $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };

        return $mime !== null && $contents !== '' ? 'data:'.$mime.';base64,'.base64_encode($contents) : null;
    }

    private static function timezone(Site $site): string
    {
        return $site->timezone ?: (string) config('app.timezone');
    }
}
