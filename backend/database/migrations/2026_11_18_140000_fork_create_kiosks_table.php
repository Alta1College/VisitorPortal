<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 120);
            // One-time code an administrator types on the device; hashed, short-lived.
            $table->char('enrolment_code_hash', 64)->nullable()->unique();
            $table->timestamp('enrolment_code_expires_at')->nullable();
            // Long random secret held by the device in an encrypted, HttpOnly cookie; hashed here.
            $table->char('device_token_hash', 64)->nullable()->unique();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('is_staffed')->default(false);
            $table->unsignedSmallInteger('idle_timeout_seconds')->default(90);
            $table->timestamps();
        });

        // Additive column on upstream's attendance pivot: which kiosk recorded the sign-in.
        Schema::table('visit_visitor', function (Blueprint $table): void {
            $table->foreignId('kiosk_id')->nullable()->after('checked_in_via')->constrained('kiosks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_visitor', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('kiosk_id');
        });

        Schema::dropIfExists('kiosks');
    }
};
