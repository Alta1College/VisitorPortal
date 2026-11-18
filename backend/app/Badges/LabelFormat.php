<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

/**
 * A label stock the badge can be printed on: physical size and the printable area in dots
 * at 300 dpi. Add a format here to support another stock; nothing assumes one size.
 */
final class LabelFormat
{
    public function __construct(
        public readonly string $key,
        public readonly int $widthMm,
        public readonly int $lengthMm,
        /** Printable width in dots (across the print head). */
        public readonly int $widthDots,
        /** Printable length in dots (along the feed). */
        public readonly int $lengthDots,
        public readonly bool $dieCut,
    ) {}

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        return [
            // 62 × 100 mm die-cut labels, e.g. Brother DK-11202.
            '62x100' => new self('62x100', 62, 100, 696, 1109, true),
        ];
    }

    public static function find(?string $key): ?self
    {
        return $key !== null ? (self::all()[$key] ?? null) : null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $format): string => __(':width × :length mm (gestanzt)', ['width' => $format->widthMm, 'length' => $format->lengthMm]),
            self::all(),
        );
    }
}
