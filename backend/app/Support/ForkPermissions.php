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

    /** Visitor types, form fields and policies. */
    public const MANAGE_VISITOR_WORKFLOW = 'Manage:VisitorWorkflow';

    /** Register, set up and revoke kiosk devices. */
    public const MANAGE_KIOSKS = 'Manage:Kiosks';

    public const MANAGE_QR_CHECK_IN = 'Manage:QrCheckIn';

    /** Set up network label printers for badges. */
    public const MANAGE_BADGE_PRINTERS = 'Manage:BadgePrinters';

    /** See who is on site, and start, run and end emergency roll calls (sites the user can see). */
    public const RUN_ROLL_CALL = 'Run:RollCall';

    public const ADMIN = [
        self::MANAGE_ORGANISATION_SETTINGS,
        self::MANAGE_SITE_SETTINGS,
        self::VIEW_AUDIT_EVENTS,
        self::MANAGE_VISITOR_WORKFLOW,
        self::MANAGE_KIOSKS,
        self::MANAGE_QR_CHECK_IN,
        self::MANAGE_BADGE_PRINTERS,
        self::RUN_ROLL_CALL,
    ];

    public const RECEPTIONIST = [
        self::RUN_ROLL_CALL,
    ];
}
