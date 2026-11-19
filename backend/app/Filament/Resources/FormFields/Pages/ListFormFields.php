<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\FormFields\Pages;

use App\Filament\Resources\FormFields\FormFieldResource;
use Filament\Resources\Pages\ListRecords;

class ListFormFields extends ListRecords
{
    protected static string $resource = FormFieldResource::class;
}
