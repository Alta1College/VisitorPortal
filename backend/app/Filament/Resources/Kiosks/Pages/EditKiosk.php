<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Kiosks\Pages;

use App\Filament\Resources\Kiosks\KioskResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKiosk extends EditRecord
{
    protected static string $resource = KioskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            KioskResource::setupCodeAction(),
            KioskResource::revokeAction(),
            // Hidden by the policy once the kiosk has recorded sign-ins: revoke instead.
            DeleteAction::make(),
        ];
    }
}
