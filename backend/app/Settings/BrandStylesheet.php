<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings;

/**
 * Organisation design tokens as CSS custom properties. Layouts consume the variables
 * (var(--brand-primary)) instead of hard-coding organisation colours in components.
 */
final class BrandStylesheet
{
    public const TOKENS = ['primary', 'secondary', 'accent', 'foreground', 'background'];

    /**
     * @return array<string, string> token => validated #rrggbb colour
     */
    public static function tokens(): array
    {
        $tokens = [];

        foreach (self::TOKENS as $token) {
            $value = config('organisation.colors.'.$token);

            // Only well-formed hex colours reach the stylesheet: no CSS injection via settings or env.
            if (is_string($value) && preg_match(SettingsRegistry::COLOR_PATTERN, $value) === 1) {
                $tokens[$token] = strtolower($value);
            }
        }

        return $tokens;
    }

    public static function css(): string
    {
        $lines = [];

        foreach (self::tokens() as $token => $value) {
            $lines[] = "    --brand-{$token}: {$value};";
        }

        return $lines === []
            ? ":root {}\n"
            : ":root {\n".implode("\n", $lines)."\n}\n";
    }

    /**
     * Cache-busting version for the stylesheet URL.
     */
    public static function version(): string
    {
        return substr(hash('sha256', self::css()), 0, 12);
    }

    public static function url(): string
    {
        return route('brand.stylesheet', ['v' => self::version()]);
    }
}
