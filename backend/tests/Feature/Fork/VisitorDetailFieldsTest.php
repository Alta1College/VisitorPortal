<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Filament\Resources\VisitorTypes\Pages\CreateVisitorType;
use App\Filament\Resources\VisitorTypes\Pages\EditVisitorType;
use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Kiosk\Livewire\KioskScreen;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\PublicVisit\VisitorDetails;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class VisitorDetailFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function parent(): VisitorType
    {
        return VisitorType::query()->create([
            'name' => 'Parent', 'slug' => 'parent',
            'detail_fields' => [
                'name' => ['label' => 'Family name'],
                'email' => ['mode' => 'optional'],
                'phone' => ['mode' => 'required', 'label' => 'Mobile'],
                'company' => ['mode' => 'hidden'],
            ],
        ]);
    }

    public function test_types_without_settings_use_the_organisation_default(): void
    {
        config(['privacy.visitor_contact_requirement' => 'require_email']);
        $type = VisitorType::query()->create(['name' => 'Visitor', 'slug' => 'visitor']);

        $this->assertSame(['first_name', 'name', 'email', 'phone', 'company'], VisitorDetails::visible($type));
        $this->assertSame(['first_name' => true, 'name' => true, 'email' => true, 'phone' => false, 'company' => false], VisitorDetails::required($type));
    }

    public function test_a_type_hides_requires_and_relabels_fields(): void
    {
        $type = $this->parent();

        $this->assertSame(['first_name', 'name', 'email', 'phone'], VisitorDetails::visible($type));
        $this->assertSame('Mobile', VisitorDetails::labels($type)['phone']);
        $this->assertSame('Family name', VisitorDetails::labels($type)['name']);

        try {
            VisitorDetails::validate(['first_name' => 'Pat', 'name' => 'Parent'], $type);
            $this->fail('Phone should be required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
            $this->assertStringContainsString('Mobile', $e->errors()['phone'][0]);
        }

        // A hidden field is dropped even if submitted.
        $details = VisitorDetails::validate(['first_name' => 'Pat', 'name' => 'Parent', 'phone' => '0400 000 000', 'company' => 'Sneaky Pty'], $type);
        $this->assertNull($details['company']);
    }

    public function test_names_cannot_be_hidden_and_email_or_phone_needs_both_fields(): void
    {
        $type = VisitorType::query()->create(['name' => 'X', 'slug' => 'x', 'detail_fields' => [
            'first_name' => ['mode' => 'hidden'],
            'email' => ['mode' => 'optional'],
            'phone' => ['mode' => 'optional'],
            'email_or_phone' => true,
        ]]);

        $this->assertContains('first_name', VisitorDetails::visible($type));
        $this->expectException(ValidationException::class);
        VisitorDetails::validate(['first_name' => 'A', 'name' => 'B'], $type);
    }

    public function test_either_rule_is_ignored_when_a_contact_field_is_hidden(): void
    {
        $type = VisitorType::query()->create(['name' => 'X', 'slug' => 'x', 'detail_fields' => [
            'phone' => ['mode' => 'hidden'],
            'email_or_phone' => true,
        ]]);

        $this->assertSame('A', VisitorDetails::validate(['first_name' => 'A', 'name' => 'B'], $type)['first_name']);
    }

    public function test_the_kiosk_shows_only_the_types_fields_and_custom_headings(): void
    {
        Notification::fake();
        $site = Site::factory()->create();
        $type = $this->parent();
        $type->update(['host_requirement' => VisitorType::HOST_NONE]);
        $reception = User::factory()->create(['site_id' => $site->getKey(), 'is_active' => true]);
        app(SettingsRepository::class)->saveSite($site, [
            'organisation.reception_host_user_id' => $reception->getKey(),
            'organisation.visitor_experience.heading_details' => 'About you',
            'organisation.visitor_experience.heading_done' => 'Welcome, :name',
            'organisation.visitor_experience.show_greeting' => '0',
        ], null);

        $kiosk = Kiosk::query()->create(['site_id' => $site->getKey(), 'name' => 'Front']);
        [, $secret] = app(KioskDevices::class)->enrol(app(KioskDevices::class)->issueEnrolmentCode($kiosk));

        Livewire::withCookie(KioskDevices::COOKIE, $secret)->test(KioskScreen::class)
            ->assertDontSee(__('Guten Morgen!'))->assertDontSee(__('Guten Tag!'))->assertDontSee(__('Guten Abend!'))
            ->call('chooseType', 'parent')->call('lookupReturning')
            ->assertSee('About you')->assertSee('Mobile')->assertSee('Family name')->assertDontSee(__('Firma'))
            ->set('details.first_name', 'Pat')->set('details.name', 'Parent')
            ->call('submitDetails')->assertHasErrors('details.phone')
            ->set('details.phone', '0400 000 000')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'confirm')
            ->call('finish')->assertSet('step', 'done')->assertSee('Welcome, Pat');

        $this->assertSame('0400 000 000', Visitor::query()->sole()->phone);
    }

    public function test_admin_sets_the_detail_fields_of_a_type(): void
    {
        VisitorPortalPermissions::sync();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)->test(CreateVisitorType::class)
            ->assertFormSet(['detail_fields.company.mode' => VisitorDetails::OPTIONAL])
            ->fillForm([
                'name' => 'Parent', 'slug' => 'parent',
                'detail_fields.company.mode' => VisitorDetails::HIDDEN,
                'detail_fields.phone.mode' => VisitorDetails::REQUIRED,
                'detail_fields.phone.label' => 'Mobile',
            ])
            ->call('create')->assertHasNoFormErrors();

        $type = VisitorType::query()->where('slug', 'parent')->sole();
        $this->assertSame(['first_name', 'name', 'email', 'phone'], VisitorDetails::visible($type));
        $this->assertSame('Mobile', VisitorDetails::labels($type)['phone']);

        // Existing types without settings show the organisation default.
        $old = VisitorType::query()->create(['name' => 'Old', 'slug' => 'old']);
        Livewire::actingAs($admin)->test(EditVisitorType::class, ['record' => $old->getKey()])
            ->assertFormSet(['detail_fields.email.mode' => VisitorDetails::OPTIONAL, 'detail_fields.first_name.mode' => VisitorDetails::REQUIRED]);
    }
}
