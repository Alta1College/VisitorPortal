<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\PublicVisit;

/**
 * How a visitor reached the public surface. Recorded on the attendance and in the audit trail.
 */
enum Channel: string
{
    case Kiosk = 'kiosk';
    case Qr = 'qr';
    case EmailLink = 'email_link';
}
