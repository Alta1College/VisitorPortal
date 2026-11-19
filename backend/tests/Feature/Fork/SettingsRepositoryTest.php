<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditEvent;
use App\Models\Site;
use App\Settings\Models\AppSetting;
use App\Settings\SettingsRepository;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }

    public function test_stored_organisation_values_overlay_config_and_clearing_restores_the_default(): void
    {
        $default = config('branding.name');

        $this->settings()->saveOrganisation(['branding.name' => 'Northside School', 'privacy.visit_retention_days' => '730'], null);

        $this->assertSame('Northside School', config('branding.name'));
        $this->assertSame(730, config('privacy.visit_retention_days'));

        $this->settings()->saveOrganisation(['branding.name' => null, 'privacy.visit_retention_days' => ''], null);

        $this->assertSame($default, config('branding.name'));
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_saving_the_unchanged_default_does_not_pin_a_stored_value(): void
    {
        $changes = $this->settings()->saveOrganisation(['app.locale' => config('app.locale')], null);

        $this->assertSame([], $changes);
        $this->assertDatabaseCount('app_settings', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_changes_are_audited_with_old_and_new_values(): void
    {
        $this->settings()->saveOrganisation(['organisation.colors.primary' => '#1E40AF'], null);

        $event = AuditEvent::query()->sole();

        $this->assertSame('settings.organisation.updated', $event->action);
        $this->assertSame(AuditEvent::RESULT_SUCCESS, $event->result);
        $this->assertSame(['old' => null, 'new' => '#1e40af'], $event->context['changes']['organisation.colors.primary']);
    }

    public function test_site_lookup_is_site_override_then_organisation_then_default(): void
    {
        $site = Site::factory()->create();
        $default = config('organisation.visitor_experience.welcome_heading');
        $resolver = fn () => $this->settings()->forSite($site->fresh());

        $this->assertSame($default, $resolver()->get('organisation.visitor_experience.welcome_heading'));

        $this->settings()->saveOrganisation(['organisation.visitor_experience.welcome_heading' => 'Welcome'], null);
        $this->assertSame('Welcome', $resolver()->get('organisation.visitor_experience.welcome_heading'));

        $this->settings()->saveSite($site, ['organisation.visitor_experience.welcome_heading' => 'Welcome to the junior campus'], null);
        $this->assertSame('Welcome to the junior campus', $resolver()->get('organisation.visitor_experience.welcome_heading'));
        $this->assertTrue($resolver()->isOverridden('organisation.visitor_experience.welcome_heading'));

        // Organisation changes do not touch a site that overrides; clearing the override inherits again.
        $this->settings()->saveOrganisation(['organisation.visitor_experience.welcome_heading' => 'Hello'], null);
        $this->assertSame('Welcome to the junior campus', $resolver()->get('organisation.visitor_experience.welcome_heading'));

        $this->settings()->saveSite($site, ['organisation.visitor_experience.welcome_heading' => null], null);
        $this->assertSame('Hello', $resolver()->get('organisation.visitor_experience.welcome_heading'));
        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_other_sites_are_not_affected_by_an_override(): void
    {
        [$a, $b] = Site::factory()->count(2)->create();

        $this->settings()->saveSite($a, ['branding.badge_accent_color' => '#00aa00'], null);

        $this->assertSame('#00aa00', $this->settings()->forSite($a)->get('branding.badge_accent_color'));
        $this->assertSame(config('branding.badge_accent_color'), $this->settings()->forSite($b)->get('branding.badge_accent_color'));
    }

    public function test_settings_that_are_not_site_overridable_are_rejected_for_sites(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->saveSite(Site::factory()->create(), ['privacy.visit_retention_days' => 30], null);
    }

    public function test_unknown_settings_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->saveOrganisation(['app.key' => 'base64:attack'], null);
    }

    public function test_long_running_processes_pick_up_settings_saved_elsewhere(): void
    {
        $worker = $this->settings();
        $worker->applyOverlay();

        // Another process saves: rows change and the shared cache version is bumped.
        AppSetting::query()->create(['key' => 'branding.name', 'value' => 'Saved elsewhere']);
        Cache::forget('fork:settings:organisation');
        Cache::forever('fork:settings:version', 'changed-by-another-process');

        $worker->refreshIfStale();

        $this->assertSame('Saved elsewhere', config('branding.name'));
    }

    public function test_image_settings_resolve_under_public_storage_and_replaced_files_are_deleted(): void
    {
        Storage::fake('public');
        // The repository only stores paths; upload validation is covered by SafeRasterImage.
        Storage::disk('public')->put('branding/old.png', 'old');
        Storage::disk('public')->put('branding/new.png', 'new');

        $this->settings()->saveOrganisation(['branding.logo_light' => 'branding/old.png'], null);
        $this->assertSame('storage/branding/old.png', config('branding.logo_light'));

        $this->settings()->saveOrganisation(['branding.logo_light' => 'branding/new.png'], null);

        Storage::disk('public')->assertMissing('branding/old.png');
        Storage::disk('public')->assertExists('branding/new.png');
    }

    public function test_image_cleanup_never_deletes_outside_the_branding_directory(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('monitor/slide.png', 'x');
        AppSetting::query()->create(['key' => 'branding.logo_dark', 'value' => 'monitor/slide.png']);
        Cache::flush();

        $this->settings()->saveOrganisation(['branding.logo_dark' => null], null);

        Storage::disk('public')->assertExists('monitor/slide.png');
    }

    public function test_browser_locale_falls_back_to_the_organisation_default(): void
    {
        $this->settings()->saveOrganisation(['app.locale' => 'de'], null);

        $request = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'ja-JP']);

        $this->assertSame('de', UserPreferences::browserLocale($request));
    }

    public function test_default_site_uses_the_install_timezone(): void
    {
        config(['app.timezone' => 'Australia/Perth']);

        Site::query()->where('slug', Site::DEFAULT_SLUG)->delete();

        $this->assertSame('Australia/Perth', Site::default()->timezone);
    }
}
