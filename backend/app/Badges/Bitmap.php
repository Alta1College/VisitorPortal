<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

use InvalidArgumentException;

/**
 * A black-and-white image for a label printer: one string per row, one byte per dot
 * ("\x01" black, "\x00" white). Built from a rendered PNG with Floyd–Steinberg dithering, so
 * text stays crisp and photos keep their shading on a one-colour thermal head.
 */
final class Bitmap
{
    /**
     * @param  list<string>  $rows
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $rows,
    ) {}

    public static function fromPng(string $png, int $threshold = 128): self
    {
        [$width, $height, $luminance] = self::decodePng($png);

        // Floyd–Steinberg error diffusion on 0–255 luminance.
        $rows = [];
        $next = array_fill(0, $width, 0.0);

        for ($y = 0; $y < $height; $y++) {
            $current = $next;
            $next = array_fill(0, $width, 0.0);
            // Contrast curve: dark greys (logos, anti-aliased text) print solid, near-white stays
            // blank, only real mid-tones (photos, light accents) are dithered.
            $line = array_map(fn (int $v): int => $v <= 96 ? 0 : ($v >= 208 ? 255 : (int) round(($v - 96) * 255 / 112)), $luminance[$y]);
            $row = '';

            for ($x = 0; $x < $width; $x++) {
                $old = $line[$x] + $current[$x];
                $black = $old < $threshold;
                $error = $old - ($black ? 0 : 255);
                $row .= $black ? "\x01" : "\x00";

                if ($x + 1 < $width) {
                    $current[$x + 1] += $error * 7 / 16;
                    $next[$x + 1] += $error / 16;
                }
                if ($x > 0) {
                    $next[$x - 1] += $error * 3 / 16;
                }
                $next[$x] += $error * 5 / 16;
            }

            $rows[] = $row;
        }

        return new self($width, $height, $rows);
    }

    /**
     * Minimal PNG decoder (8-bit greyscale/RGB with or without alpha, not interlaced), enough
     * for Chromium screenshots without needing the GD extension.
     *
     * @return array{0: int, 1: int, 2: list<list<int>>} width, height, luminance rows
     */
    public static function decodePng(string $png): array
    {
        if (! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new InvalidArgumentException('Not a PNG image.');
        }

        $offset = 8;
        $data = '';
        $header = null;

        while ($offset + 8 <= strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $chunk = substr($png, $offset + 8, $length);
            $offset += 12 + $length;

            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $chunk);
            } elseif ($type === 'IDAT') {
                $data .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }

        $channels = [0 => 1, 2 => 3, 4 => 2, 6 => 4][$header['type'] ?? -1] ?? null;

        if ($header === null || $channels === null || $header['depth'] !== 8 || $header['interlace'] !== 0) {
            throw new InvalidArgumentException('Unsupported PNG format (8-bit, non-interlaced greyscale or RGB expected).');
        }

        $raw = @gzuncompress($data);

        if ($raw === false) {
            throw new InvalidArgumentException('Corrupt PNG image data.');
        }

        $width = $header['width'];
        $height = $header['height'];
        $stride = $width * $channels;
        $previous = array_fill(0, $stride, 0);
        $luminance = [];

        for ($y = 0; $y < $height; $y++) {
            $start = $y * ($stride + 1);
            $filter = ord($raw[$start]);
            $line = array_values(unpack('C*', substr($raw, $start + 1, $stride)));

            for ($i = 0; $i < $stride; $i++) {
                $left = $i >= $channels ? $line[$i - $channels] : 0;
                $up = $previous[$i];
                $upLeft = $i >= $channels ? $previous[$i - $channels] : 0;

                $line[$i] = match ($filter) {
                    0 => $line[$i],
                    1 => ($line[$i] + $left) & 0xFF,
                    2 => ($line[$i] + $up) & 0xFF,
                    3 => ($line[$i] + intdiv($left + $up, 2)) & 0xFF,
                    4 => ($line[$i] + self::paeth($left, $up, $upLeft)) & 0xFF,
                    default => throw new InvalidArgumentException('Corrupt PNG filter.'),
                };
            }

            $previous = $line;
            $row = [];

            for ($x = 0; $x < $width; $x++) {
                $p = $x * $channels;
                $value = $channels >= 3
                    ? (int) round(0.299 * $line[$p] + 0.587 * $line[$p + 1] + 0.114 * $line[$p + 2])
                    : $line[$p];

                // Transparent pixels print as white paper.
                $alpha = match ($channels) {
                    2 => $line[$p + 1],
                    4 => $line[$p + 3],
                    default => 255,
                };
                $row[] = (int) round(($value * $alpha + 255 * (255 - $alpha)) / 255);
            }

            $luminance[] = $row;
        }

        return [$width, $height, $luminance];
    }

    /**
     * The black-and-white image as a PNG (1-bit greyscale), to preview exactly what prints.
     */
    public function toPng(): string
    {
        $raw = '';

        foreach ($this->rows as $row) {
            $raw .= "\x00"; // filter: none
            $packed = '';

            for ($x = 0; $x < $this->width; $x += 8) {
                $value = 0xFF;

                for ($bit = 0; $bit < 8 && $x + $bit < $this->width; $bit++) {
                    if ($row[$x + $bit] === "\x01") {
                        $value &= ~(0x80 >> $bit);
                    }
                }

                $packed .= chr($value);
            }

            $raw .= $packed;
        }

        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $this->width, $this->height, 1, 0, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($raw, 9))
            .$chunk('IEND', '');
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }
}
