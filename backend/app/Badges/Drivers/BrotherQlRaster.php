<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges\Drivers;

use App\Badges\Bitmap;
use App\Badges\LabelFormat;
use InvalidArgumentException;

/**
 * Brother QL raster command language for the 720-dot (62 mm) QL-700/800 series, e.g.
 * QL-810W and QL-820NWB, sent raw to the printer's port 9100. Follows Brother's "QL Series
 * Raster Command Reference" (and the open-source brother_ql project's byte layout).
 *
 * The printer must be in raster mode (Editor Lite off); the job switches it with ESC i a.
 */
final class BrotherQlRaster implements LabelDriver
{
    /** Dots across the print head. */
    public const HEAD_DOTS = 720;

    /** Unprintable dots on the right-hand side of a 62 mm label. */
    public const RIGHT_MARGIN_DOTS = 12;

    public static function key(): string
    {
        return 'brother_ql';
    }

    public static function label(): string
    {
        return __('Brother QL-700/800-Serie (z. B. QL-820NWB), Netzwerk-Port 9100');
    }

    public function encode(Bitmap $bitmap, LabelFormat $format): string
    {
        if ($bitmap->width !== $format->widthDots || $bitmap->height !== $format->lengthDots) {
            throw new InvalidArgumentException(sprintf('Bitmap is %dx%d, label needs %dx%d dots.', $bitmap->width, $bitmap->height, $format->widthDots, $format->lengthDots));
        }

        $left = self::HEAD_DOTS - $format->widthDots - self::RIGHT_MARGIN_DOTS;

        $data = str_repeat("\x00", 400)                    // invalidate
            ."\x1B\x40"                                     // initialise
            ."\x1B\x69\x61\x01"                             // switch to raster mode
            ."\x1B\x69\x7A"                                 // print information:
            .chr(0x80 | 0x02 | 0x04 | 0x08 | 0x40)          //   valid: recover, type, width, length, quality
            .chr($format->dieCut ? 0x0B : 0x0A)             //   die-cut or continuous
            .chr($format->widthMm)
            .chr($format->dieCut ? $format->lengthMm : 0)
            .pack('V', $bitmap->height)                     //   raster lines
            ."\x00\x00"                                     //   first page, reserved
            ."\x1B\x69\x4D\x40"                             // auto cut on
            ."\x1B\x69\x41\x01"                             // cut every label
            ."\x1B\x69\x4B\x08"                             // cut at end, 300 dpi, black only
            ."\x1B\x69\x64".pack('v', $format->dieCut ? 0 : 35) // feed margin
            ."\x4D\x00";                                    // no compression

        $bytesPerRow = intdiv(self::HEAD_DOTS, 8);

        foreach ($bitmap->rows as $row) {
            // Place the label's printable area on the head, then mirror: the first bit sent is
            // the pin at the far edge.
            $line = strrev(str_repeat("\x00", $left).$row.str_repeat("\x00", self::RIGHT_MARGIN_DOTS));
            $packed = '';

            for ($byte = 0; $byte < $bytesPerRow; $byte++) {
                $value = 0;

                for ($bit = 0; $bit < 8; $bit++) {
                    if ($line[$byte * 8 + $bit] === "\x01") {
                        $value |= 0x80 >> $bit;
                    }
                }

                $packed .= chr($value);
            }

            $data .= "\x67\x00".chr($bytesPerRow).$packed;
        }

        return $data."\x1A";                                // print, last page
    }
}
