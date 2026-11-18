<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

use App\Models\User;
use App\Support\ForkPermissions;

/**
 * Registered explicitly: without a policy Filament would allow every action.
 */
class BadgePrinterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(ForkPermissions::MANAGE_BADGE_PRINTERS);
    }

    public function view(User $user, BadgePrinter $printer): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, BadgePrinter $printer): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, BadgePrinter $printer): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
