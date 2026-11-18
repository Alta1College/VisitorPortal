<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Filament\Resources\SiteQrLinks\Pages\ListSiteQrLinks;
use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Kiosk\Livewire\KioskScreen;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\QrCheckIn\Livewire\MobileSignIn;
use App\QrCheckIn\SiteQrLink;
use App\QrCheckIn\SiteQrLinks;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyAssignment;
use App\VisitorWorkflow\Models\PolicyVersion;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\PolicyPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use Tests\TestCase;

class QrCheckInTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private SiteQrLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->site = Site::factory()->create();
        User::factory()->create(['site_id' => $this->site->getKey(), 'first_name' => 'Ada', 'name' => 'Avery', 'is_active' => true]);
        VisitorType::query()->create(['name' => 'Visitor', 'slug' => 'visitor']);
        VisitorType::query()->create(['name' => 'Parent', 'slug' => 'parent']);
        $this->link = app(SiteQrLinks::class)->forSite($this->site);
    }

    public function test_a_visitor_signs_in_from_their_phone_through_the_same_workflow(): void
    {
        $policy = Policy::query()->create(['name' => 'Safety']);
        PolicyAssignment::query()->create(['policy_id' => $policy->getKey()]);
        app(PolicyPublisher::class)->publish($policy, 'Site safety', 'Wear your badge.', 'I accept', null);
        $hash = PolicyVersion::query()->value('content_hash');

        $this->get('/visit/'.$this->link->token)->assertOk()->assertSee('Visitor')->assertSee('Parent');

        Livewire::test(MobileSignIn::class, ['token' => $this->link->token])
            ->call('chooseType', 'visitor')->call('lookupReturning')
            ->set('details.first_name', 'Pat')->set('details.name', 'Phone')
            ->call('submitDetails')->assertSet('step', 'host')
            // Internet-reachable: two letters are not enough to search the staff directory.
            ->set('hostQuery', 'Ad')->assertSet('hostResults', [])
            ->set('hostQuery', 'Ada')->assertSee('Ada Avery')
            ->call('chooseHost', 0)->assertSet('step', 'policies')
            ->set("accepted.{$hash}", true)
            ->call('submitPolicies')->assertSet('step', 'confirm')
            ->call('finish')->assertHasNoErrors()->assertSet('step', 'done')->assertSee('Pat');

        $attendance = DB::table('visit_visitor')->sole();
        $this->assertSame('qr', $attendance->checked_in_via);
        $this->assertNull($attendance->kiosk_id);
        $this->assertSame($this->site->getKey(), Visit::query()->sole()->site_id);
        $this->assertDatabaseHas('policy_acceptances', ['channel' => 'qr']);
    }

    public function test_unknown_disabled_and_replaced_links_do_not_work(): void
    {
        $this->get('/visit/'.str_repeat('a', 32))->assertNotFound();
        $this->get('/visit/short')->assertNotFound();

        $screen = Livewire::test(MobileSignIn::class, ['token' => $this->link->token])->call('chooseType', 'visitor')->call('lookupReturning');
        $old = $this->link->token;

        app(SiteQrLinks::class)->rotate($this->link);

        $this->get('/visit/'.$old)->assertNotFound();
        // An open page stops working at once: the link is re-checked on every update.
        $screen->call('submitDetails')->assertNotFound();
        $this->get('/visit/'.$this->link->fresh()->token)->assertOk();

        $this->link->update(['is_enabled' => false]);
        $this->get('/visit/'.$this->link->token)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'qr_link.rotated']);
    }

    public function test_the_mobile_page_holds_no_database_ids(): void
    {
        $screen = Livewire::test(MobileSignIn::class, ['token' => $this->link->token])
            ->call('chooseType', 'visitor')->call('lookupReturning')
            ->set('details.first_name', 'Pat')->set('details.name', 'Phone')
            ->call('submitDetails')->set('hostQuery', 'Ada');

        $snapshot = json_encode($screen->snapshot['data']);
        $this->assertStringNotContainsString('"id"', $snapshot);
        $this->assertStringNotContainsString('site_id', $snapshot);
    }

    public function test_the_kiosk_shows_the_sites_qr_code_while_it_is_enabled(): void
    {
        $kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front']);
        [, $secret] = app(KioskDevices::class)->enrol(app(KioskDevices::class)->issueEnrolmentCode($kiosk));

        Livewire::withCookie(KioskDevices::COOKIE, $secret)->test(KioskScreen::class)
            ->assertSee(__('Mobil anmelden'))->assertSeeHtml('<svg');

        $this->link->update(['is_enabled' => false]);

        Livewire::withCookie(KioskDevices::COOKIE, $secret)->test(KioskScreen::class)
            ->assertDontSee(__('Mobil anmelden'));
    }

    public function test_the_qr_code_uses_the_public_address_when_set(): void
    {
        $links = app(SiteQrLinks::class);
        $this->assertStringEndsWith('/visit/'.$this->link->token, $links->url($this->link));

        app(SettingsRepository::class)->saveOrganisation(['organisation.public_url' => 'https://visit.example.org/'], null);

        $this->assertSame('https://visit.example.org/visit/'.$this->link->token, $links->url($this->link));
        $this->assertStringStartsWith('<svg', $links->svg($links->url($this->link)));
    }

    public function test_admin_sets_up_shows_prints_and_replaces_a_qr_code(): void
    {
        VisitorPortalPermissions::sync();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $other = Site::factory()->create(['name' => 'Junior Campus']);

        Livewire::actingAs($admin)->test(ListSiteQrLinks::class)
            ->assertCanSeeTableRecords([$this->link])
            ->callAction('add', ['site_id' => $other->getKey()])
            ->assertHasNoActionErrors();

        $new = SiteQrLink::query()->where('site_id', $other->getKey())->sole();
        $this->assertDatabaseHas('audit_events', ['action' => 'qr_link.created']);

        $token = $new->token;
        Livewire::actingAs($admin)->test(ListSiteQrLinks::class)
            ->callTableAction('rotate', $new);
        $this->assertNotSame($token, $new->fresh()->token);

        // Staff pages keep the normal web stack: admins need two-factor set up first.
        $this->actingAs($admin)->get(route('qr-check-in.poster', $new))->assertRedirect(route('security.mfa.required'));
        $this->flushSession();
        app(EnableTwoFactorAuthentication::class)($admin);
        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin->refresh())
            ->withSession(['auth.app_mfa_satisfied_at' => time(), 'auth.app_mfa_satisfied_for_auth_method' => 'local'])
            ->get(route('qr-check-in.poster', $new))
            ->assertOk()->assertSee('Junior Campus')->assertSee('/visit/'.$new->fresh()->token)->assertSee('<svg', false);
    }

    public function test_staff_without_permission_cannot_manage_or_print_qr_codes(): void
    {
        VisitorPortalPermissions::sync();
        $user = User::factory()->create();

        $this->get(route('qr-check-in.poster', $this->link))->assertRedirect();
        $this->actingAs($user)->get(route('qr-check-in.poster', $this->link))->assertForbidden();
        Livewire::actingAs($user)->test(ListSiteQrLinks::class)->assertForbidden();
    }
}
