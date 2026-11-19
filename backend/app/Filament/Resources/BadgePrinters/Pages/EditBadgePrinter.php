<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\BadgePrinters\Pages;

use App\Filament\Resources\BadgePrinters\BadgePrinterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBadgePrinter extends EditRecord
{
    protected static string $resource = BadgePrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BadgePrinterResource::testPrintAction(),
            BadgePrinterResource::previewAction(),
            DeleteAction::make(),
        ];
    }
}
