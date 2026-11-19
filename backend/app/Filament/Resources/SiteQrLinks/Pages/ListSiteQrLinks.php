<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\SiteQrLinks\Pages;

use App\Filament\Resources\SiteQrLinks\SiteQrLinkResource;
use Filament\Resources\Pages\ListRecords;

class ListSiteQrLinks extends ListRecords
{
    protected static string $resource = SiteQrLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [SiteQrLinkResource::addAction()];
    }
}
