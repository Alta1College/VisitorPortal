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
        // One mobile check-in link per site: /visit/{token}. The token is printed on posters and
        // shown on kiosks, so it is public by nature; it is random (not guessable) and can be
        // replaced to stop old posters working.
        Schema::create('site_qr_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_qr_links');
    }
};
