<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings;

use App\Support\UserPreferences;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Allow-list of settings administrators may change from the web GUI. Anything not listed here
 * stays environment-only (secrets, infrastructure, the storage timezone).
 */
final class SettingsRegistry
{
    public const GROUP_IDENTITY = 'identity';

    public const GROUP_BRANDING = 'branding';

    public const GROUP_VISITOR_EXPERIENCE = 'visitor_experience';

    public const GROUP_REGIONAL = 'regional';

    public const GROUP_RETENTION = 'retention';

    public const COLOR_PATTERN = '/^#[0-9a-f]{6}$/i';

    /** @var array<string, SettingDefinition>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, SettingDefinition>
     */
    public static function all(): array
    {
        return self::$definitions ??= self::build();
    }

    public static function get(string $key): SettingDefinition
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public static function siteOverridable(): array
    {
        return array_filter(self::all(), fn (SettingDefinition $definition): bool => $definition->siteOverridable);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public static function inGroup(string $group): array
    {
        return array_filter(self::all(), fn (SettingDefinition $definition): bool => $definition->group === $group);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private static function build(): array
    {
        $string = fn (int $max): array => ['string', 'max:'.$max];
        $color = ['string', 'regex:'.self::COLOR_PATTERN];
        $days = ['integer', 'min:1', 'max:36500'];

        $definitions = [
            // Identity
            new SettingDefinition('branding.name', SettingDefinition::TYPE_STRING, self::GROUP_IDENTITY, $string(120), required: true),
            new SettingDefinition('organisation.contact_email', SettingDefinition::TYPE_EMAIL, self::GROUP_IDENTITY, ['email:rfc', 'max:191'], siteOverridable: true),
            new SettingDefinition('organisation.contact_phone', SettingDefinition::TYPE_STRING, self::GROUP_IDENTITY, $string(40), siteOverridable: true),
            new SettingDefinition('organisation.address', SettingDefinition::TYPE_TEXT, self::GROUP_IDENTITY, $string(500), siteOverridable: true),

            // Branding
            new SettingDefinition('branding.logo_light', SettingDefinition::TYPE_IMAGE, self::GROUP_BRANDING, siteOverridable: true),
            new SettingDefinition('branding.logo_dark', SettingDefinition::TYPE_IMAGE, self::GROUP_BRANDING, siteOverridable: true),
            new SettingDefinition('branding.mail_logo', SettingDefinition::TYPE_IMAGE, self::GROUP_BRANDING),
            new SettingDefinition('branding.badge_logo', SettingDefinition::TYPE_IMAGE, self::GROUP_BRANDING, siteOverridable: true),
            new SettingDefinition('organisation.colors.primary', SettingDefinition::TYPE_COLOR, self::GROUP_BRANDING, $color),
            new SettingDefinition('organisation.colors.secondary', SettingDefinition::TYPE_COLOR, self::GROUP_BRANDING, $color),
            new SettingDefinition('organisation.colors.accent', SettingDefinition::TYPE_COLOR, self::GROUP_BRANDING, $color),
            new SettingDefinition('organisation.colors.foreground', SettingDefinition::TYPE_COLOR, self::GROUP_BRANDING, $color),
            new SettingDefinition('organisation.colors.background', SettingDefinition::TYPE_COLOR, self::GROUP_BRANDING, $color),
            new SettingDefinition('branding.badge_accent_color', SettingDefinition::TYPE_COLOR, self::GROUP_BRANDING, $color, siteOverridable: true),

            // Visitor experience
            new SettingDefinition('organisation.visitor_experience.welcome_heading', SettingDefinition::TYPE_STRING, self::GROUP_VISITOR_EXPERIENCE, $string(120), siteOverridable: true),
            new SettingDefinition('organisation.visitor_experience.welcome_message', SettingDefinition::TYPE_TEXT, self::GROUP_VISITOR_EXPERIENCE, $string(1000), siteOverridable: true),
            new SettingDefinition('organisation.visitor_experience.sign_in_label', SettingDefinition::TYPE_STRING, self::GROUP_VISITOR_EXPERIENCE, $string(60)),
            new SettingDefinition('organisation.visitor_experience.sign_out_label', SettingDefinition::TYPE_STRING, self::GROUP_VISITOR_EXPERIENCE, $string(60)),
            new SettingDefinition('organisation.visitor_experience.visitor_instructions', SettingDefinition::TYPE_TEXT, self::GROUP_VISITOR_EXPERIENCE, $string(2000), siteOverridable: true),
            new SettingDefinition('organisation.visitor_experience.sign_in_confirmation', SettingDefinition::TYPE_TEXT, self::GROUP_VISITOR_EXPERIENCE, $string(500)),
            new SettingDefinition('organisation.visitor_experience.sign_out_confirmation', SettingDefinition::TYPE_TEXT, self::GROUP_VISITOR_EXPERIENCE, $string(500)),
            new SettingDefinition('branding.monitor_fallback_heading', SettingDefinition::TYPE_STRING, self::GROUP_VISITOR_EXPERIENCE, $string(120)),
            new SettingDefinition('branding.monitor_fallback_subheading', SettingDefinition::TYPE_STRING, self::GROUP_VISITOR_EXPERIENCE, $string(200)),
            new SettingDefinition('organisation.reception_host_user_id', SettingDefinition::TYPE_USER, self::GROUP_VISITOR_EXPERIENCE,
                ['integer', Rule::exists('users', 'id')->where('is_active', true)], siteOverridable: true),

            // Regional
            new SettingDefinition('app.locale', SettingDefinition::TYPE_LOCALE, self::GROUP_REGIONAL, ['string', Rule::in(UserPreferences::supportedLocales())], required: true),

            // Retention (operational database retention only; backup retention is infrastructure)
            new SettingDefinition('privacy.visit_retention_days', SettingDefinition::TYPE_INTEGER, self::GROUP_RETENTION, $days, required: true),
            new SettingDefinition('privacy.notification_retention_days', SettingDefinition::TYPE_INTEGER, self::GROUP_RETENTION, $days, required: true),
            new SettingDefinition('privacy.run_log_retention_days', SettingDefinition::TYPE_INTEGER, self::GROUP_RETENTION, $days, required: true),
            new SettingDefinition('organisation.audit_retention_days', SettingDefinition::TYPE_INTEGER, self::GROUP_RETENTION, ['integer', 'min:30', 'max:36500'], required: true),
            new SettingDefinition('organisation.evidence_retention_days', SettingDefinition::TYPE_INTEGER, self::GROUP_RETENTION, $days, required: true),
        ];

        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }
}
