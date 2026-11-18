<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Kiosk;

use App\Models\User;
use App\Support\ForkPermissions;
use Illuminate\Support\Facades\DB;

/**
 * Registered explicitly: without a policy Filament would allow every action. Kiosks are
 * revoked rather than deleted while they have recorded sign-ins, to keep attendance history.
 */
class KioskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(ForkPermissions::MANAGE_KIOSKS);
    }

    public function view(User $user, Kiosk $kiosk): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Kiosk $kiosk): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Kiosk $kiosk): bool
    {
        return $this->viewAny($user)
            && ! DB::table('visit_visitor')->where('kiosk_id', $kiosk->getKey())->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
