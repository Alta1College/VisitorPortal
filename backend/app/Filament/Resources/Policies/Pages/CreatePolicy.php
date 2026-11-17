<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Filament\Resources\Policies\Pages;

use App\Filament\Resources\Policies\PolicyResource;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\PolicyPublisher;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePolicy extends CreateRecord
{
    protected static string $resource = PolicyResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $version = $data['first_version'] ?? [];
        unset($data['first_version']);

        /** @var Policy $policy */
        $policy = Policy::query()->create($data);

        app(PolicyPublisher::class)->publish(
            $policy,
            (string) ($version['title'] ?? ''),
            (string) ($version['body'] ?? ''),
            (string) ($version['acceptance_label'] ?? ''),
            auth()->user(),
        );

        return $policy;
    }
}
