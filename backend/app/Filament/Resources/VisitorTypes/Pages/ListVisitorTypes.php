<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\VisitorTypes\Pages;

use App\Filament\Resources\VisitorTypes\VisitorTypeResource;
use Filament\Resources\Pages\ListRecords;

class ListVisitorTypes extends ListRecords
{
    protected static string $resource = VisitorTypeResource::class;
}
