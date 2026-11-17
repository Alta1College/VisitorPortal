<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Settings;

/**
 * Organisation design tokens as CSS custom properties, served at /brand.css.
 *
 * - --brand-* variables for fork layouts (kiosk, mobile).
 * - The same colours mapped onto upstream's daisyUI theme variables, so the staff portal, login
 *   and welcome screens use them without editing upstream components:
 *   primary/secondary/accent in every theme (lightened in dark themes until readable),
 *   foreground/background in the light theme only and only when readable together.
 * - Button text (--color-*-content) is chosen for contrast automatically.
 */
final class BrandStylesheet
{
    public const TOKENS = ['primary', 'secondary', 'accent', 'foreground', 'background'];

    public const ACTION_TOKENS = ['primary', 'secondary', 'accent'];

    /** Upstream's dark theme surface; true-black is darker, so this is the harder case. */
    private const DARK_SURFACE = '#18181b';

    /** WCAG AA for normal text. */
    private const MIN_CONTRAST = 4.5;

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
        $tokens = self::tokens();

        if ($tokens === []) {
            return ":root {}\n";
        }

        $blocks = [self::block(':root', array_combine(
            array_map(fn (string $t): string => '--brand-'.$t, array_keys($tokens)),
            array_values($tokens),
        ))];

        $all = [];
        $dark = [];

        foreach (self::ACTION_TOKENS as $token) {
            if (! isset($tokens[$token])) {
                continue;
            }

            $all["--color-{$token}"] = $tokens[$token];
            $all["--color-{$token}-content"] = self::readableTextOn($tokens[$token]);

            $darkColour = self::readableOnDark($tokens[$token]);
            $dark["--color-{$token}"] = $darkColour;
            $dark["--color-{$token}-content"] = self::readableTextOn($darkColour);
        }

        // html[data-theme] (0,1,1) outranks upstream's [data-theme="…"] (0,1,0) regardless of order.
        if ($all !== []) {
            $blocks[] = self::block('html[data-theme]', $all);
            $blocks[] = self::block('html[data-theme="dark"], html[data-theme="true-black"]', $dark);
        }

        $foreground = $tokens['foreground'] ?? null;
        $background = $tokens['background'] ?? null;

        if ($foreground !== null && $background !== null && self::contrast($foreground, $background) >= self::MIN_CONTRAST) {
            $blocks[] = self::block('html[data-theme="light"]', [
                '--color-base-content' => $foreground,
                '--color-base-200' => $background,
            ]);
            // Upstream layouts paint the page background in an inline style; same selector, loaded later.
            $blocks[] = self::block('html[data-theme="light"], html[data-theme="light"] body', [
                'background-color' => $background,
            ]);
        }

        return implode("\n", $blocks);
    }

    /**
     * Whether foreground and background will be applied (they must be readable together).
     */
    public static function surfaceColoursApply(): bool
    {
        $tokens = self::tokens();

        return isset($tokens['foreground'], $tokens['background'])
            && self::contrast($tokens['foreground'], $tokens['background']) >= self::MIN_CONTRAST;
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

    /**
     * Black or white, whichever reads better on the colour.
     */
    public static function readableTextOn(string $hex): string
    {
        return self::contrast($hex, '#000000') >= self::contrast($hex, '#ffffff') ? '#000000' : '#ffffff';
    }

    /**
     * The colour, mixed with white in 10% steps until it is readable on the dark theme surface.
     */
    public static function readableOnDark(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        for ($white = 0; $white <= 100; $white += 10) {
            $mixed = sprintf(
                '#%02x%02x%02x',
                (int) round($r + (255 - $r) * $white / 100),
                (int) round($g + (255 - $g) * $white / 100),
                (int) round($b + (255 - $b) * $white / 100),
            );

            if (self::contrast($mixed, self::DARK_SURFACE) >= self::MIN_CONTRAST) {
                return $mixed;
            }
        }

        return '#ffffff';
    }

    /**
     * WCAG 2 contrast ratio between two #rrggbb colours (1–21).
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /**
     * @param  array<string, string>  $declarations
     */
    private static function block(string $selector, array $declarations): string
    {
        $lines = array_map(fn (string $property, string $value): string => "    {$property}: {$value};", array_keys($declarations), $declarations);

        return $selector." {\n".implode("\n", $lines)."\n}\n";
    }
}
