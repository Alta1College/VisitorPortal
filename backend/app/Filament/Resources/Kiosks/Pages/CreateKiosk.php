<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Kiosks\Pages;

use App\Filament\Resources\Kiosks\KioskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKiosk extends CreateRecord
{
    protected static string $resource = KioskResource::class;

    /**
     * Continue to the edit page, where the setup code is issued.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
