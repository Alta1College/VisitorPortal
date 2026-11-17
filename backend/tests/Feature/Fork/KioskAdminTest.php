<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Filament\Resources\Kiosks\KioskResource;
use App\Filament\Resources\Kiosks\Pages\CreateKiosk;
use App\Filament\Resources\Kiosks\Pages\EditKiosk;
use App\Filament\Resources\Kiosks\Pages\ListKiosks;
use App\Kiosk\Kiosk;
use App\Models\Site;
use App\Models\User;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KioskAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        VisitorPortalPermissions::sync();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->site = Site::factory()->create();
    }

    public function test_admin_creates_a_kiosk_issues_a_code_and_revokes_it(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ListKiosks::class)
            ->assertOk()
            ->assertSee(KioskResource::getUrl('create'), false);

        Livewire::actingAs($this->admin)
            ->test(CreateKiosk::class)
            ->fillForm(['name' => 'Front desk', 'site_id' => $this->site->getKey(), 'idle_timeout_seconds' => 120])
            ->call('create')
            ->assertHasNoFormErrors();

        $kiosk = Kiosk::query()->sole();
        $this->assertSame('not_enrolled', $kiosk->status());

        Livewire::actingAs($this->admin)
            ->test(EditKiosk::class, ['record' => $kiosk->getKey()])
            ->callAction('setupCode')
            ->assertNotified();

        $this->assertSame('awaiting_enrolment', $kiosk->fresh()->status());
        $this->assertNotNull($kiosk->fresh()->getAttributes()['enrolment_code_hash']);

        Livewire::actingAs($this->admin)
            ->test(EditKiosk::class, ['record' => $kiosk->getKey()])
            ->callAction('revoke');

        $this->assertSame('revoked', $kiosk->fresh()->status());
        $this->assertDatabaseHas('audit_events', ['action' => 'kiosk.revoked']);
    }

    public function test_warning_when_a_site_offers_host_less_types_without_a_reception_contact(): void
    {
        $kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front desk']);

        $this->assertFalse(KioskResource::receptionContactMissing($kiosk->fresh()));

        VisitorType::query()->create(['name' => 'Delivery', 'slug' => 'delivery', 'host_requirement' => VisitorType::HOST_NONE]);
        $this->assertTrue(KioskResource::receptionContactMissing($kiosk->fresh()));

        Livewire::actingAs($this->admin)
            ->test(EditKiosk::class, ['record' => $kiosk->getKey()])
            ->assertSee(__('An diesem Standort gibt es Besuchertypen ohne Pflicht-Gastgeber, aber keinen Empfangskontakt. Solche Besucher können sich am Kiosk nicht anmelden, bis unter Standorteinstellungen ein Empfangskontakt gesetzt ist.'), false);
    }

    public function test_users_without_permission_cannot_manage_kiosks(): void
    {
        $receptionist = User::factory()->create();
        $receptionist->assignRole('receptionist');

        Livewire::actingAs($receptionist)->test(ListKiosks::class)->assertForbidden();
    }
}
