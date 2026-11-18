<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
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
