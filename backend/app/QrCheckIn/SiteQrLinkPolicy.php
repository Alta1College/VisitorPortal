<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\QrCheckIn;

use App\Models\User;
use App\Support\ForkPermissions;

/**
 * Registered explicitly: without a policy Filament would allow every action. Links are turned
 * off or replaced, never deleted (one per site).
 */
class SiteQrLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(ForkPermissions::MANAGE_QR_CHECK_IN);
    }

    public function view(User $user, SiteQrLink $link): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SiteQrLink $link): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, SiteQrLink $link): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
