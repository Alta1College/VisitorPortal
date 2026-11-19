<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Filament\Pages\ManageOrganisationSettings;
use App\Filament\Pages\ManageSiteSettings;
use App\Models\Site;
use App\Models\User;
use App\Settings\SettingsRepository;
use App\Support\ForkPermissions;
use App\Support\VisitorPortalPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        VisitorPortalPermissions::sync();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_admin_role_holds_the_fork_permissions(): void
    {
        $admin = $this->userWithRole('admin');

        foreach (ForkPermissions::ADMIN as $permission) {
            $this->assertTrue($admin->can($permission), $permission);
        }

        $this->assertFalse($this->userWithRole('receptionist')->can(ForkPermissions::MANAGE_ORGANISATION_SETTINGS));
    }

    public function test_admin_can_update_organisation_settings(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ManageOrganisationSettings::class)
            ->assertOk()
            ->fillForm([
                'branding__name' => 'Riverside College',
                'organisation__contact_email' => 'office@example.test',
                'organisation__colors__primary' => '#0055aa',
                'privacy__visit_retention_days' => 1095,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Riverside College', config('branding.name'));
        $this->assertSame('office@example.test', config('organisation.contact_email'));
        $this->assertSame(1095, config('privacy.visit_retention_days'));
        $this->assertDatabaseHas('audit_events', ['action' => 'settings.organisation.updated']);
    }

    public function test_organisation_settings_are_validated(): void
    {
        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ManageOrganisationSettings::class)
            ->fillForm([
                'branding__name' => '',
                'organisation__contact_email' => 'not-an-email',
                'organisation__colors__primary' => 'javascript:alert(1)',
                'privacy__visit_retention_days' => 0,
                'organisation__audit_retention_days' => 5,
                'app__locale' => 'xx',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'branding__name' => 'required',
                'organisation__contact_email',
                'organisation__colors__primary',
                'privacy__visit_retention_days',
                'organisation__audit_retention_days',
                'app__locale',
            ]);

        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_admin_can_upload_a_raster_logo(): void
    {
        Storage::fake('public');
        $png = UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG_1X1));

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ManageOrganisationSettings::class)
            ->fillForm(['branding__logo_light' => $png])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = app(SettingsRepository::class)->organisationValues()['branding.logo_light'];

        $this->assertStringStartsWith('branding/', $stored);
        Storage::disk('public')->assertExists($stored);
        $this->assertSame('storage/'.$stored, config('branding.logo_light'));
    }

    public function test_svg_logos_are_rejected(): void
    {
        Storage::fake('public');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(ManageOrganisationSettings::class)
            ->fillForm(['branding__logo_light' => $svg])
            ->call('save')
            ->assertHasFormErrors(['branding__logo_light']);

        $this->assertDatabaseMissing('app_settings', ['key' => 'branding.logo_light']);
    }

    public function test_users_without_permission_cannot_open_settings_pages(): void
    {
        $receptionist = $this->userWithRole('receptionist');

        Livewire::actingAs($receptionist)->test(ManageOrganisationSettings::class)->assertForbidden();
        Livewire::actingAs($receptionist)->test(ManageSiteSettings::class)->assertForbidden();
    }

    public function test_admin_can_override_and_clear_site_settings(): void
    {
        $admin = $this->userWithRole('admin');
        $site = Site::factory()->create(['name' => 'Junior Campus']);

        Livewire::actingAs($admin)
            ->withQueryParams(['site' => $site->getKey()])
            ->test(ManageSiteSettings::class)
            ->assertOk()
            ->assertSet('siteId', $site->getKey())
            ->fillForm([
                'organisation__visitor_experience__welcome_heading' => 'Welcome to Junior Campus',
                'branding__badge_accent_color' => '#22aa22',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SettingsRepository::class)->forSite($site);
        $this->assertSame('Welcome to Junior Campus', $settings->get('organisation.visitor_experience.welcome_heading'));
        $this->assertDatabaseHas('audit_events', ['action' => 'settings.site.updated', 'site_id' => $site->getKey()]);

        Livewire::actingAs($admin)
            ->withQueryParams(['site' => $site->getKey()])
            ->test(ManageSiteSettings::class)
            ->fillForm(['organisation__visitor_experience__welcome_heading' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(app(SettingsRepository::class)->forSite($site)->isOverridden('organisation.visitor_experience.welcome_heading'));
        $this->assertTrue(app(SettingsRepository::class)->forSite($site)->isOverridden('branding.badge_accent_color'));
    }
}
