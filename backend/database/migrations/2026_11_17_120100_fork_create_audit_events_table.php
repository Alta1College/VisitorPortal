<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
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
        // Append-only. actor_user_id and site_id deliberately have no foreign keys so that
        // audit history survives deletion of the user or site it refers to.
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at', 6)->index();
            $table->string('action', 100)->index();
            $table->string('result', 20);
            $table->string('channel', 20);
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->json('context')->nullable();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
