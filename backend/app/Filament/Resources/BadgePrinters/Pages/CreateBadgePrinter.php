<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\BadgePrinters\Pages;

use App\Filament\Resources\BadgePrinters\BadgePrinterResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBadgePrinter extends CreateRecord
{
    protected static string $resource = BadgePrinterResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
