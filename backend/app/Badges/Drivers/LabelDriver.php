<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges\Drivers;

use App\Badges\Bitmap;
use App\Badges\LabelFormat;

/**
 * Turns a black-and-white badge image into the bytes a label printer understands.
 */
interface LabelDriver
{
    public static function key(): string;

    public static function label(): string;

    public function encode(Bitmap $bitmap, LabelFormat $format): string;
}
