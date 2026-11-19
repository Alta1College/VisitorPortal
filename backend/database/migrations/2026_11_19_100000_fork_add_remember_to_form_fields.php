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
 * Per question: pre-fill the answer for returning visitors (e.g. WWCC number, photo), or ask
 * afresh each visit (e.g. reason for visit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table): void {
            $table->boolean('remember_for_returning')->default(false)->after('show_on_roll_call');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table): void {
            $table->dropColumn('remember_for_returning');
        });
    }
};
