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
        // Additive columns on upstream's attendance pivot: how the visitor arrived and left
        // (staff | kiosk | qr | email_link). Null = staff, as before.
        Schema::table('visit_visitor', function (Blueprint $table): void {
            $table->string('checked_in_via', 20)->nullable()->after('checked_in_by_user_id');
            $table->string('checked_out_via', 20)->nullable()->after('checked_out_by_user_id');
        });

        // Idempotency for public submissions: a retried or double-tapped submission returns the
        // first result instead of creating a second visit. Keys are stored hashed.
        Schema::create('public_submissions', function (Blueprint $table): void {
            $table->id();
            $table->char('key_hash', 64)->unique();
            $table->string('channel', 20);
            $table->foreignId('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->timestamp('created_at')->index();
        });

        // Opaque, single-use tokens that let a visitor act on their own attendance without an
        // account (sign-out). Only the SHA-256 of the token is stored.
        Schema::create('attendance_tokens', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('purpose', 20);
            $table->foreignId('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_tokens');
        Schema::dropIfExists('public_submissions');

        Schema::table('visit_visitor', function (Blueprint $table): void {
            $table->dropColumn(['checked_in_via', 'checked_out_via']);
        });
    }
};
