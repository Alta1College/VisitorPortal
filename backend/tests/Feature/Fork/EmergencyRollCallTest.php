<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Emergency\AutoSignOut;
use App\Emergency\Livewire\OnSitePage;
use App\Emergency\Livewire\RollCallPage;
use App\Emergency\OnSiteRegister;
use App\Emergency\RollCall;
use App\Emergency\RollCallEntry;
use App\Emergency\RollCalls;
use App\Models\Site;
use App\Models\User;
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\ArrivalRequest;
use App\PublicVisit\Commands\ArrivalResult;
use App\PublicVisit\Commands\RegisterArrival;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use Tests\TestCase;

class EmergencyRollCallTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $otherSite;

    private VisitorType $type;

    private User $warden;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        VisitorPortalPermissions::sync();
        $this->travelTo(now()->setTime(10, 0));

        $this->site = Site::factory()->create(['name' => 'North Campus', 'timezone' => config('app.timezone')]);
        $this->otherSite = Site::factory()->create(['name' => 'Central Office', 'timezone' => config('app.timezone')]);

        foreach ([$this->site, $this->otherSite] as $site) {
            $reception = User::factory()->create(['site_id' => $site->getKey(), 'is_active' => true]);
            app(SettingsRepository::class)->saveSite($site, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        }

        $this->type = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_NONE]);
        $mobility = FormField::query()->create(['key' => 'mobility', 'label' => 'Needs help evacuating', 'type' => FormField::TYPE_YES_NO, 'show_on_roll_call' => true, 'is_sensitive' => true]);
        $reason = FormField::query()->create(['key' => 'reason', 'label' => 'Reason', 'type' => FormField::TYPE_SHORT_TEXT]);
        FormFieldAssignment::query()->create(['form_field_id' => $mobility->getKey(), 'visitor_type_id' => $this->type->getKey(), 'sort_order' => 1]);
        FormFieldAssignment::query()->create(['form_field_id' => $reason->getKey(), 'visitor_type_id' => $this->type->getKey(), 'sort_order' => 2]);

        $this->warden = User::factory()->create(['site_id' => $this->site->getKey()]);
        $this->warden->assignRole('receptionist');
    }

    private function arrive(string $first, ?Site $site = null, array $answers = []): ArrivalResult
    {
        return app(RegisterArrival::class)->handle(new ArrivalRequest(
            site: $site ?? $this->site,
            channel: Channel::Kiosk,
            submissionKey: (string) Str::uuid(),
            visitorType: $this->type,
            details: ['first_name' => $first, 'name' => 'Visitor', 'company' => 'Acme'],
            hostToken: null,
            answers: $answers,
            acceptedPolicyVersionIds: [],
        ));
    }

    private function signOut(ArrivalResult $result): void
    {
        DB::table('visit_visitor')->where('visit_id', $result->visitId)->where('visitor_id', $result->visitorId)
            ->update(['checked_out_at' => now(), 'checked_out_via' => 'kiosk']);
    }

    public function test_the_register_lists_everyone_signed_in_with_safety_notes(): void
    {
        $this->arrive('Ada', answers: ['mobility' => '1', 'reason' => 'Wiring']);
        $gone = $this->arrive('Ben');
        $this->signOut($gone);
        $this->arrive('Cara', $this->otherSite);

        // Signed in yesterday and never signed out: still listed, flagged.
        $this->travel(-1)->days();
        $forgot = $this->arrive('Dan');
        $this->travelBack();
        $this->travelTo(now()->setTime(10, 0));
        // Even if the visit itself was closed, the person counts as on site.
        DB::table('visits')->where('id', $forgot->visitId)->update(['status' => 'completed']);

        $people = collect(app(OnSiteRegister::class)->people($this->site))->keyBy('first_name');

        $this->assertSame(['Ada', 'Dan'], $people->keys()->sort()->values()->all());
        $this->assertSame(['Needs help evacuating' => __('Ja')], $people['Ada']['safety']);
        $this->assertFalse($people['Ada']['earlier_day']);
        $this->assertTrue($people['Dan']['earlier_day']);
        $this->assertSame('Contractor', $people['Ada']['visitor_type']);

        Livewire::actingAs($this->warden)->test(OnSitePage::class)
            ->assertSee('Ada Visitor')->assertSee('Needs help evacuating')->assertDontSee('Wiring')->assertDontSee('Cara');
    }

    public function test_a_roll_call_is_a_snapshot_that_later_sign_outs_do_not_change(): void
    {
        $ada = $this->arrive('Ada');
        $this->arrive('Ben');

        Livewire::actingAs($this->warden)->test(OnSitePage::class)
            ->call('startRollCall', 'emergency')->assertRedirect();

        $rollCall = RollCall::query()->sole();
        $this->assertSame(2, $rollCall->entries()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'roll_call.started']);

        // Pressing start again (another warden) opens the same roll call.
        $this->assertTrue(app(RollCalls::class)->start($this->site, $this->warden, 'emergency')->is($rollCall));

        $this->travel(5)->minutes();
        $this->signOut($ada);
        $late = $this->arrive('Cara');

        $page = Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $rollCall])
            ->assertSee('Ada Visitor')
            ->assertSee(__('Abgemeldet um :time', ['time' => now()->format('H:i').' (kiosk)']))
            ->assertSee(__('Nach dem Start angemeldet'))->assertSee('Cara Visitor');

        $page->call('addLate', $late->visitId, $late->visitorId);
        $this->assertSame(3, $rollCall->entries()->count());
        $this->assertSame(RollCallEntry::SOURCE_LATE, $rollCall->entries()->where('first_name', 'Cara')->value('source'));
    }

    public function test_wardens_mark_people_add_others_and_end_the_roll_call(): void
    {
        $this->arrive('Ada', answers: ['mobility' => '1']);
        $this->arrive('Ben');
        $rollCall = app(RollCalls::class)->start($this->site, $this->warden, RollCall::KIND_DRILL);
        $ada = $rollCall->entries()->where('first_name', 'Ada')->sole();
        $ben = $rollCall->entries()->where('first_name', 'Ben')->sole();

        $this->assertSame(['Needs help evacuating' => __('Ja')], $ada->safety_notes);
        $this->assertStringNotContainsString('evacuating', (string) DB::table('roll_call_entries')->where('id', $ada->id)->value('safety_notes'), 'encrypted at rest');

        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $rollCall])
            ->assertSee(trans_choice(':count Person nicht erfasst|:count Personen nicht erfasst', 2, ['count' => 2]))
            ->call('mark', $ada->id, RollCallEntry::ACCOUNTED)
            ->call('mark', $ben->id, RollCallEntry::LEFT_SITE)
            ->set('manualName', 'Unknown contractor')->set('manualNote', 'Found at the gym')->call('addManual')
            ->assertSee(trans_choice(':count Person nicht erfasst|:count Personen nicht erfasst', 0, ['count' => 0]))
            ->call('end');

        $this->assertSame(['unaccounted' => 0, 'accounted' => 2, 'left_site' => 1], $rollCall->fresh()->counts());
        $this->assertSame($this->warden->id, $ada->fresh()->status_changed_by_user_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'roll_call.marked']);
        $this->assertDatabaseHas('audit_events', ['action' => 'roll_call.person_added']);
        $this->assertDatabaseHas('audit_events', ['action' => 'roll_call.ended']);

        // Ended: read-only.
        Livewire::actingAs($this->warden)->test(RollCallPage::class, ['rollCall' => $rollCall])
            ->call('mark', $ada->id, RollCallEntry::UNACCOUNTED)->assertStatus(409);
        $this->assertSame(RollCallEntry::ACCOUNTED, $ada->fresh()->status);
    }

    public function test_access_is_limited_to_the_users_sites_and_permission(): void
    {
        $this->arrive('Ada');
        $rollCall = app(RollCalls::class)->start($this->site, $this->warden, RollCall::KIND_EMERGENCY);

        $plain = User::factory()->create(['site_id' => $this->site->getKey()]);
        Livewire::actingAs($plain)->test(OnSitePage::class)->assertForbidden();

        $elsewhere = User::factory()->create(['site_id' => $this->otherSite->getKey()]);
        $elsewhere->assignRole('receptionist');
        Livewire::actingAs($elsewhere)->test(RollCallPage::class, ['rollCall' => $rollCall])->assertForbidden();
        Livewire::actingAs($elsewhere)->test(OnSitePage::class)->assertDontSee('Ada Visitor');

        app(EnableTwoFactorAuthentication::class)($this->warden);
        $this->warden->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($this->warden->refresh())
            ->withSession(['auth.app_mfa_satisfied_at' => time(), 'auth.app_mfa_satisfied_for_auth_method' => 'local'])
            ->get(route('reception.roll-calls.print', $rollCall))
            ->assertOk()->assertSee('Ada Visitor')->assertSee(__('Anwesenheitskontrolle'));
    }

    public function test_automatic_sign_out_per_site_and_never_during_a_roll_call(): void
    {
        app(SettingsRepository::class)->saveOrganisation(['organisation.visitor_experience.auto_sign_out_time' => '18:00'], null);
        app(SettingsRepository::class)->saveSite($this->otherSite, ['organisation.visitor_experience.auto_sign_out_time' => '21:30'], null);

        $ada = $this->arrive('Ada');
        $board = $this->arrive('Board member', $this->otherSite);
        $auto = app(AutoSignOut::class);

        $this->travelTo(now()->setTime(17, 59));
        $this->assertSame(0, $auto->run());

        $this->travelTo(now()->setTime(18, 5));
        $this->assertSame(1, $auto->run());
        $this->assertSame('auto', DB::table('visit_visitor')->where('visit_id', $ada->visitId)->value('checked_out_via'));
        $this->assertNull(DB::table('visit_visitor')->where('visit_id', $board->visitId)->value('checked_out_at'), 'Central Office runs until 21:30');
        $this->assertSame(0, DB::table('attendance_tokens')->where('visit_id', $ada->visitId)->whereNull('used_at')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'attendance.auto_signed_out']);

        // An open roll call at a site freezes automatic sign-out there.
        app(RollCalls::class)->start($this->otherSite, $this->warden, RollCall::KIND_DRILL);
        $this->travelTo(now()->setTime(21, 40));
        $this->assertSame(0, $auto->run());
        $this->assertNull(DB::table('visit_visitor')->where('visit_id', $board->visitId)->value('checked_out_at'));

        // "off" turns it off for a site.
        app(SettingsRepository::class)->saveSite($this->site, ['organisation.visitor_experience.auto_sign_out_time' => 'off'], null);
        $this->arrive('Late worker');
        $this->travelTo(now()->addDay()->setTime(19, 0));
        $this->assertSame(0, $auto->runForSite($this->site));
    }

    public function test_ended_roll_calls_are_purged_after_the_retention_period(): void
    {
        $this->arrive('Ada');
        $rollCall = app(RollCalls::class)->start($this->site, $this->warden, RollCall::KIND_DRILL);
        app(RollCalls::class)->end($rollCall, $this->warden);
        $open = app(RollCalls::class)->start($this->otherSite, $this->warden, RollCall::KIND_DRILL);

        $this->travel(400)->days();
        $this->artisan('visitorportal:roll-calls:purge')->assertSuccessful();

        $this->assertDatabaseMissing('roll_calls', ['id' => $rollCall->id]);
        $this->assertDatabaseHas('roll_calls', ['id' => $open->id]);
        $this->assertSame(0, RollCallEntry::query()->where('roll_call_id', $rollCall->id)->count());
    }
}
