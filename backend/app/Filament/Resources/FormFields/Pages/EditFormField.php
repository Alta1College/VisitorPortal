<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\FormFields\Pages;

use App\Filament\Resources\FormFields\FormFieldResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFormField extends EditRecord
{
    protected static string $resource = FormFieldResource::class;

    protected function getHeaderActions(): array
    {
        // Hidden by the policy while another field's condition refers to this one.
        return [DeleteAction::make()];
    }
}
