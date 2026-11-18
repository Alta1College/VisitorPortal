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
        Schema::table('visitor_types', function (Blueprint $table): void {
            // Which personal details this type asks for (hidden / optional / required, label).
            // Null: the organisation default (upstream's visitor contact requirement).
            $table->json('detail_fields')->nullable()->after('host_requirement');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_types', function (Blueprint $table): void {
            $table->dropColumn('detail_fields');
        });
    }
};
