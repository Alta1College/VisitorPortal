<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network label printers that the server prints badges to directly (no print dialog), which
 * kiosk prints to which printer, and which visitor types get a badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_printers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('host', 255);
            $table->unsignedInteger('port')->default(9100);
            $table->string('driver', 40);
            $table->string('label', 40);
            $table->boolean('is_enabled')->default(true);
            // Prints badges for sign-ins that have no kiosk printer (e.g. QR / phone sign-ins).
            $table->boolean('is_site_default')->default(false);
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::table('kiosks', function (Blueprint $table): void {
            $table->foreignId('badge_printer_id')->nullable()->after('idle_timeout_seconds')->constrained('badge_printers')->nullOnDelete();
        });

        Schema::table('visitor_types', function (Blueprint $table): void {
            $table->boolean('print_badge')->default(true)->after('host_requirement');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_types', function (Blueprint $table): void {
            $table->dropColumn('print_badge');
        });

        Schema::table('kiosks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('badge_printer_id');
        });

        Schema::dropIfExists('badge_printers');
    }
};
