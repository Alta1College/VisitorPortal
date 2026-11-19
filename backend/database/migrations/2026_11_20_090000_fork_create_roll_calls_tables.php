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
 * Emergency roll calls (brief §21). A roll call is a snapshot of everyone signed in at a site
 * when it starts; entries copy what wardens need, so later changes to visits (sign-out,
 * cancellation, purge) never silently remove anyone from the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('kind', 20); // emergency | drill
            $table->timestamp('started_at');
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'ended_at']);
        });

        Schema::create('roll_call_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('roll_call_id')->constrained('roll_calls')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('visitor_id')->nullable()->constrained('visitors')->nullOnDelete();
            // snapshot: on the list at the start | late: signed in after the start | manual: added by hand
            $table->string('source', 20);
            $table->string('first_name', 191);
            $table->string('last_name', 191)->nullable();
            $table->string('company', 191)->nullable();
            $table->string('visitor_type', 191)->nullable();
            $table->string('host_name', 191)->nullable();
            $table->timestamp('checked_in_at')->nullable();
            // Answers marked "show on roll call" (may be sensitive): encrypted JSON.
            $table->text('safety_notes')->nullable();
            $table->string('status', 20)->default('unaccounted');
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['roll_call_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roll_call_entries');
        Schema::dropIfExists('roll_calls');
    }
};
