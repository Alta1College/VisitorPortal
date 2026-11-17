<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings\Filament;

use App\Rules\SafeRasterImage;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsRepository;
use App\Support\RasterImageUpload;
use App\Support\UserPreferences;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Builds Filament form fields from setting definitions, shared by the organisation and site
 * settings pages so labels, validation and upload handling stay identical.
 */
final class SettingFields
{
    /**
     * German source strings, translated through lang/*.json like the rest of the app.
     *
     * @return array<string, array{0: string, 1?: string}> key => [label, helper text]
     */
    public static function texts(): array
    {
        return [
            'branding.name' => ['Name der Organisation'],
            'organisation.contact_email' => ['Kontakt-E-Mail'],
            'organisation.contact_phone' => ['Kontakttelefon'],
            'organisation.address' => ['Anschrift'],
            'branding.logo_light' => ['Logo (helles Design)', 'PNG, JPEG oder WebP.'],
            'branding.logo_dark' => ['Logo (dunkles Design)', 'PNG, JPEG oder WebP.'],
            'branding.mail_logo' => ['Logo in E-Mails'],
            'branding.badge_logo' => ['Logo auf Besucherausweisen'],
            'organisation.colors.primary' => ['Primärfarbe', 'Wird auch als Hauptfarbe im Verwaltungsbereich verwendet.'],
            'organisation.colors.secondary' => ['Sekundärfarbe'],
            'organisation.colors.accent' => ['Akzentfarbe'],
            'organisation.colors.foreground' => ['Vordergrundfarbe'],
            'organisation.colors.background' => ['Hintergrundfarbe'],
            'branding.badge_accent_color' => ['Akzentfarbe des Besucherausweises'],
            'organisation.visitor_experience.welcome_heading' => ['Begrüßungsüberschrift'],
            'organisation.visitor_experience.welcome_message' => ['Begrüßungstext'],
            'organisation.visitor_experience.sign_in_label' => ['Beschriftung „Anmelden“'],
            'organisation.visitor_experience.sign_out_label' => ['Beschriftung „Abmelden“'],
            'organisation.visitor_experience.visitor_instructions' => ['Hinweise für Besucher'],
            'organisation.visitor_experience.sign_in_confirmation' => ['Bestätigung nach der Anmeldung'],
            'organisation.visitor_experience.sign_out_confirmation' => ['Bestätigung nach der Abmeldung'],
            'branding.monitor_fallback_heading' => ['Überschrift der Willkommensanzeige'],
            'branding.monitor_fallback_subheading' => ['Unterzeile der Willkommensanzeige'],
            'app.locale' => ['Standardsprache', 'Gilt, wenn weder Benutzer noch Browser eine unterstützte Sprache vorgeben.'],
            'privacy.visit_retention_days' => ['Aufbewahrung von Besuchen (Tage)', 'Ältere Besuche werden automatisch gelöscht, sofern keine Aufbewahrungssperre besteht.'],
            'privacy.notification_retention_days' => ['Aufbewahrung von Benachrichtigungen (Tage)'],
            'privacy.run_log_retention_days' => ['Aufbewahrung der Löschprotokolle (Tage)'],
            'organisation.audit_retention_days' => ['Aufbewahrung des Audit-Protokolls (Tage)', 'Mindestens 30 Tage.'],
            'organisation.evidence_retention_days' => ['Aufbewahrung von Nachweisdateien (Tage)', 'Fotos, Unterschriften und hochgeladene Nachweise werden danach gelöscht. Die Antworten selbst bleiben bis zur Löschung des Besuchs erhalten.'],
        ];
    }

    public static function label(string $key): string
    {
        return __(self::texts()[$key][0] ?? $key);
    }

    /**
     * @param  string|null  $inheritedHint  Shown on site pages: the value used when the field is left empty.
     */
    public static function make(SettingDefinition $definition, bool $required, ?string $inheritedHint = null): Field
    {
        $field = match ($definition->type) {
            SettingDefinition::TYPE_TEXT => Textarea::make($definition->field())->rows(3),
            SettingDefinition::TYPE_EMAIL => TextInput::make($definition->field())->email(),
            SettingDefinition::TYPE_COLOR => ColorPicker::make($definition->field())->hex()->regex(SettingsRegistry::COLOR_PATTERN),
            SettingDefinition::TYPE_INTEGER => TextInput::make($definition->field())->integer(),
            SettingDefinition::TYPE_LOCALE => Select::make($definition->field())->options(self::localeOptions())->native(false),
            SettingDefinition::TYPE_IMAGE => self::imageUpload($definition),
            default => TextInput::make($definition->field()),
        };

        if ($definition->type !== SettingDefinition::TYPE_IMAGE) {
            $field->rules(array_values(array_filter(
                $definition->rules,
                // The colour picker already applies the pattern; avoid a duplicate message.
                fn ($rule): bool => ! (is_string($rule) && str_starts_with($rule, 'regex:')),
            )));
        }

        $helper = self::texts()[$definition->key][1] ?? null;
        $helperText = trim(implode(' ', array_filter([
            $helper !== null ? __($helper) : null,
            $inheritedHint,
        ])));

        return $field
            ->label(self::label($definition->key))
            ->required($required)
            ->helperText($helperText !== '' ? $helperText : null);
    }

    /**
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        $names = ['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'cs' => 'Čeština'];
        $options = [];

        foreach (UserPreferences::supportedLocales() as $locale) {
            $options[$locale] = $names[$locale] ?? $locale;
        }

        return $options;
    }

    private static function imageUpload(SettingDefinition $definition): FileUpload
    {
        return FileUpload::make($definition->field())
            ->image()
            ->disk(SettingsRepository::IMAGE_DISK)
            ->directory(SettingsRepository::IMAGE_DIRECTORY)
            ->visibility('public')
            // Raster only (no SVG). SafeRasterImage re-checks the real content and strips metadata.
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
            ->maxSize(RasterImageUpload::maxSizeKilobytes())
            ->rule(new SafeRasterImage);
    }
}
