<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
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
 * Give existing installs' admin and receptionist roles the roll call permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(ForkPermissions::RUN_ROLL_CALL, 'web');

        Role::query()->whereIn('name', ['admin', 'receptionist'])->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(ForkPermissions::RUN_ROLL_CALL));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::query()->where('name', ForkPermissions::RUN_ROLL_CALL)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
