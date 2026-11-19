<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Kiosks\Pages;

use App\Filament\Resources\Kiosks\KioskResource;
use Filament\Resources\Pages\ListRecords;

class ListKiosks extends ListRecords
{
    protected static string $resource = KioskResource::class;
}
