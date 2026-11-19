<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use App\Support\ForkPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Existing installs already have their roles; give the admin role the new fork permissions so
 * administrators can reach the new pages without re-running the permission sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (ForkPermissions::ADMIN as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::query()->where('name', 'admin')->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(ForkPermissions::ADMIN));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->whereIn('name', ForkPermissions::ADMIN)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
