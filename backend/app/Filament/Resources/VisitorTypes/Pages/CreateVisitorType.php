<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\VisitorTypes\Pages;

use App\Filament\Resources\VisitorTypes\VisitorTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVisitorType extends CreateRecord
{
    protected static string $resource = VisitorTypeResource::class;
}
