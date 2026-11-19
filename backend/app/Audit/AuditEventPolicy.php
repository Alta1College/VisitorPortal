<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Audit;

use App\Models\User;
use App\Support\ForkPermissions;

/**
 * Audit events can be read by permitted users and never changed by anyone.
 */
class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(ForkPermissions::VIEW_AUDIT_EVENTS);
    }

    public function view(User $user, AuditEvent $event): bool
    {
        return $user->can(ForkPermissions::VIEW_AUDIT_EVENTS);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, AuditEvent $event): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
