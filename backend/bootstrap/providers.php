<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use App\Audit\AuditServiceProvider;
use App\Badges\BadgesServiceProvider;
use App\Messaging\MessagingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\PublicVisit\PublicVisitServiceProvider;
use App\Settings\SettingsServiceProvider;
use App\VisitorWorkflow\VisitorWorkflowServiceProvider;

return [
    // Fork providers (see docs/fork/upstream-patches.md). Settings must precede the admin panel.
    AuditServiceProvider::class,
    SettingsServiceProvider::class,
    VisitorWorkflowServiceProvider::class,
    PublicVisitServiceProvider::class,
    MessagingServiceProvider::class,
    BadgesServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AdminPanelProvider::class,
];
