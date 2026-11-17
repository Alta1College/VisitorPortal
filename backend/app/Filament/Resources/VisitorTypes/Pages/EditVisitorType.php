<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\VisitorTypes\Pages;

use App\Filament\Resources\VisitorTypes\VisitorTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVisitorType extends EditRecord
{
    protected static string $resource = VisitorTypeResource::class;

    protected function getHeaderActions(): array
    {
        // Hidden by the policy once the type has been used on a visit: deactivate it instead.
        return [DeleteAction::make()];
    }
}
