<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

/**
 * Permissions added by the fork. Merged into the admin role by VisitorPortalPermissions and
 * granted to existing installs by the fork permissions migration.
 */
final class ForkPermissions
{
    public const MANAGE_ORGANISATION_SETTINGS = 'Manage:OrganisationSettings';

    public const MANAGE_SITE_SETTINGS = 'Manage:SiteSettings';

    public const VIEW_AUDIT_EVENTS = 'ViewAny:AuditEvent';

    public const ADMIN = [
        self::MANAGE_ORGANISATION_SETTINGS,
        self::MANAGE_SITE_SETTINGS,
        self::VIEW_AUDIT_EVENTS,
    ];
}
