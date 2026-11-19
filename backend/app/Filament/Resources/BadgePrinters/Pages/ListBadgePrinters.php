<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\BadgePrinters\Pages;

use App\Filament\Resources\BadgePrinters\BadgePrinterResource;
use Filament\Resources\Pages\ListRecords;

class ListBadgePrinters extends ListRecords
{
    protected static string $resource = BadgePrinterResource::class;
}
