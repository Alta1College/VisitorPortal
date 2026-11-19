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
        Schema::create('visitor_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            // required | optional | none. Upstream visits need a host; "none" is resolved by the
            // public command layer (Phase 4) using the site's reception fallback host.
            $table->string('host_requirement', 20)->default('required');
            $table->string('badge_label', 40)->nullable();
            $table->string('badge_color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // No rows for a type = available at every site.
        Schema::create('visitor_type_site', function (Blueprint $table): void {
            $table->foreignId('visitor_type_id')->constrained('visitor_types')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->primary(['visitor_type_id', 'site_id']);
        });

        Schema::create('form_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('label', 200);
            $table->text('help_text')->nullable();
            $table->string('type', 30);
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('show_on_badge')->default(false);
            $table->boolean('show_on_roll_call')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // visitor_type_id / site_id null = applies to all types / all sites.
        Schema::create('form_field_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('form_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->foreignId('visitor_type_id')->nullable()->constrained('visitor_types')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Immutable once created: a new wording is a new version.
        Schema::create('policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->longText('body');
            $table->string('acceptance_label', 200);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamp('published_at');
            $table->unique(['policy_id', 'version']);
        });

        // visitor_type_id / site_id null = all types / all sites (both null = organisation-wide).
        Schema::create('policy_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('visitor_type_id')->nullable()->constrained('visitor_types')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->timestamps();
        });

        // One attendance = (visit_id, visitor_id), matching upstream's visit_visitor pivot.
        Schema::create('visit_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->foreignId('form_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
            // Snapshots: editing or deleting a field never changes what was answered.
            $table->string('field_key', 80);
            $table->string('field_label', 200);
            $table->string('field_type', 30);
            // Values of fields marked sensitive are stored encrypted (APP_KEY).
            $table->boolean('is_sensitive')->default(false);
            $table->text('value')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();
            $table->unique(['visit_id', 'visitor_id', 'field_key']);
        });

        Schema::create('policy_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->foreignId('policy_version_id')->constrained('policy_versions')->restrictOnDelete();
            $table->timestamp('accepted_at');
            $table->string('channel', 20);
            $table->unique(['visit_id', 'visitor_id', 'policy_version_id']);
        });

        // Additive column on upstream's attendance pivot (recorded in upstream-patches.md).
        Schema::table('visit_visitor', function (Blueprint $table): void {
            $table->foreignId('visitor_type_id')->nullable()->after('visitor_id')->constrained('visitor_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_visitor', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visitor_type_id');
        });

        Schema::dropIfExists('policy_acceptances');
        Schema::dropIfExists('visit_responses');
        Schema::dropIfExists('policy_assignments');
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('policies');
        Schema::dropIfExists('form_field_assignments');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('visitor_type_site');
        Schema::dropIfExists('visitor_types');
    }
};
