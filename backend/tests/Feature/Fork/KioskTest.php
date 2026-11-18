<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Kiosk\Http\RequireKioskDevice;
use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Kiosk\Livewire\KioskScreen;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyAssignment;
use App\VisitorWorkflow\Models\PolicyVersion;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\PolicyPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

class KioskTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Kiosk $kiosk;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->site = Site::factory()->create();
        User::factory()->create(['site_id' => $this->site->getKey(), 'first_name' => 'Ada', 'name' => 'Avery', 'is_active' => true]);
        $this->kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front desk']);

        $code = app(KioskDevices::class)->issueEnrolmentCode($this->kiosk);
        [, $this->secret] = app(KioskDevices::class)->enrol($code);
    }

    private function screen()
    {
        return Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class);
    }

    private function contractorWithConditionalCard(): VisitorType
    {
        $type = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_OPTIONAL]);
        VisitorType::query()->create(['name' => 'Visitor', 'slug' => 'visitor']);

        $question = FormField::query()->create(['key' => 'works_with_children', 'label' => 'Children?', 'type' => FormField::TYPE_YES_NO]);
        FormFieldAssignment::query()->create(['form_field_id' => $question->getKey(), 'visitor_type_id' => $type->getKey(), 'is_required' => true, 'sort_order' => 1]);
        $card = FormField::query()->create([
            'key' => 'wwc_card', 'label' => 'Card', 'type' => FormField::TYPE_SHORT_TEXT, 'is_sensitive' => true,
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'works_with_children', 'operator' => 'equals', 'value' => '1']]],
        ]);
        FormFieldAssignment::query()->create(['form_field_id' => $card->getKey(), 'visitor_type_id' => $type->getKey(), 'is_required' => true, 'sort_order' => 2]);

        $policy = Policy::query()->create(['name' => 'Safety']);
        PolicyAssignment::query()->create(['policy_id' => $policy->getKey()]);
        app(PolicyPublisher::class)->publish($policy, 'Site safety', 'Wear your badge.', 'I accept', null);

        return $type;
    }

    public function test_a_contractor_signs_in_end_to_end(): void
    {
        $this->contractorWithConditionalCard();
        $hash = PolicyVersion::query()->value('content_hash');

        $screen = $this->screen()
            ->assertSee('Contractor')->assertSee('Visitor')
            ->call('chooseType', 'contractor')->assertSet('step', 'details')
            ->set('details.first_name', 'Casey')->set('details.name', 'Contractor')->set('details.company', 'Spark Electrical')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'host')
            ->set('hostQuery', 'Ada')->assertSee('Ada Avery')
            ->call('chooseHost', 0)->assertSet('step', 'questions')
            ->set('answers.works_with_children', '1')
            ->call('submitQuestions')->assertHasErrors(['answers.wwc_card'])->assertSet('step', 'questions')
            ->set('answers.wwc_card', 'WWC-123456')
            ->call('submitQuestions')->assertHasNoErrors()->assertSet('step', 'policies')
            ->call('submitPolicies')->assertHasErrors()->assertSet('step', 'policies')
            ->set("accepted.{$hash}", true)
            ->call('submitPolicies')->assertSet('step', 'confirm')
            ->call('finish')->assertHasNoErrors()->assertSet('step', 'done')
            ->assertSee('Casey');

        $attendance = DB::table('visit_visitor')->sole();
        $this->assertSame('kiosk', $attendance->checked_in_via);
        $this->assertSame($this->kiosk->getKey(), $attendance->kiosk_id);
        $this->assertNotNull($attendance->checked_in_at);
        $this->assertDatabaseHas('policy_acceptances', ['channel' => 'kiosk']);
        $this->assertDatabaseHas('visit_responses', ['field_key' => 'wwc_card', 'is_sensitive' => true]);
        $this->assertDatabaseMissing('visit_responses', ['value' => 'WWC-123456']);

        // Finishing again (double tap) does not create a second visit.
        $screen->call('finish');
        $this->assertSame(1, Visit::query()->count());
    }

    public function test_steps_that_do_not_apply_are_skipped(): void
    {
        $reception = User::factory()->create(['site_id' => $this->site->getKey(), 'is_active' => true]);
        app(SettingsRepository::class)->saveSite($this->site, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        VisitorType::query()->create(['name' => 'Delivery', 'slug' => 'delivery', 'host_requirement' => VisitorType::HOST_NONE]);

        // No host, questions or policies: straight from details to confirm.
        $this->screen()
            ->call('chooseType', 'delivery')->assertSet('step', 'details')
            ->set('details.first_name', 'Dee')->set('details.name', 'Livery')
            ->call('submitDetails')->assertSet('step', 'confirm')
            ->call('finish')->assertSet('step', 'done');

        $this->assertSame($reception->getKey(), Visit::query()->sole()->host_user_id);
    }

    public function test_restart_clears_everything_the_visitor_entered(): void
    {
        $this->contractorWithConditionalCard();

        $screen = $this->screen()
            ->call('chooseType', 'contractor')
            ->set('details.first_name', 'Casey')->set('details.name', 'Contractor');
        $key = $screen->get('submissionKey');

        $screen->call('restart')
            ->assertSet('step', 'welcome')
            ->assertSet('typeSlug', null)
            ->assertSet('details.first_name', null)
            ->assertSet('answers', [])
            ->assertNotSet('submissionKey', $key);
    }

    public function test_the_browser_never_receives_database_ids(): void
    {
        $this->contractorWithConditionalCard();

        $screen = $this->screen()
            ->call('chooseType', 'contractor')
            ->set('details.first_name', 'Casey')->set('details.name', 'Contractor')
            ->call('submitDetails')
            ->set('hostQuery', 'Ada');

        $snapshot = json_encode($screen->snapshot['data']);

        $this->assertStringNotContainsString('"site_id"', $snapshot);
        $this->assertStringNotContainsString('"id"', $snapshot);
        $this->assertStringNotContainsString('kiosk', strtolower(json_encode(array_keys($screen->snapshot['data']))));
        $this->assertStringContainsString('Ada Avery', $snapshot);
    }

    public function test_an_unknown_or_revoked_device_cannot_use_the_kiosk(): void
    {
        $this->get('/kiosk')->assertRedirect(route('kiosk.enrol'));
        $this->withCookie(KioskDevices::COOKIE, str_repeat('x', 64))->get('/kiosk')->assertRedirect(route('kiosk.enrol'));
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get('/kiosk')->assertOk()->assertSee(__('Gäste, bitte hier beginnen.'));

        app(KioskDevices::class)->revoke($this->kiosk);

        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get('/kiosk')->assertRedirect(route('kiosk.enrol'));
        // Component updates are re-checked too (persistent middleware), and refused outright.
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->withHeader('X-Livewire', '1')->get('/kiosk')->assertForbidden();
        Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class)->assertForbidden();
    }

    public function test_the_welcome_screen_lists_visitor_types_and_greets_by_time_of_day(): void
    {
        $this->contractorWithConditionalCard();
        $this->site->update(['timezone' => 'Australia/Perth']);

        $this->travelTo(now('Australia/Perth')->setTime(8, 30));
        $this->screen()->assertSet('step', 'welcome')
            ->assertSeeInOrder([__('Guten Morgen!'), __('Was führt Sie zu uns?'), 'Contractor', 'Visitor'])
            ->call('chooseType', 'not-a-type')->assertHasErrors('type')->assertSet('step', 'welcome');

        $this->travelTo(now('Australia/Perth')->setTime(18, 0));
        $this->screen()->assertSee(__('Guten Abend!'));

        VisitorType::query()->update(['is_active' => false]);
        $this->screen()->assertSee(__('Die Anmeldung ist hier gerade nicht möglich. Bitte wenden Sie sich an den Empfang.'));
    }

    public function test_the_kiosk_device_check_runs_on_livewire_updates(): void
    {
        $this->assertContains(
            RequireKioskDevice::class,
            app(PersistentMiddleware::class)->getPersistentMiddleware(),
        );
    }

    public function test_enrolment_codes_are_single_use_and_expire(): void
    {
        $kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Side door']);
        $devices = app(KioskDevices::class);

        $this->post('/kiosk/enrol', ['code' => 'WRONG-CODE'])->assertSessionHasErrors('code');

        $code = $devices->issueEnrolmentCode($kiosk);
        $response = $this->post('/kiosk/enrol', ['code' => strtolower($code)]);
        $response->assertRedirect(route('kiosk'))->assertCookie(KioskDevices::COOKIE);

        $this->post('/kiosk/enrol', ['code' => $code])->assertSessionHasErrors('code');

        $expired = $devices->issueEnrolmentCode($kiosk);
        $this->travel(KioskDevices::CODE_TTL_HOURS + 1)->hours();
        $this->post('/kiosk/enrol', ['code' => $expired])->assertSessionHasErrors('code');

        $this->assertDatabaseHas('audit_events', ['action' => 'kiosk.enrolled']);
        $this->assertDatabaseHas('audit_events', ['action' => 'kiosk.enrolment_failed']);
    }

    public function test_a_new_code_disconnects_the_previous_device(): void
    {
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get('/kiosk')->assertOk();

        app(KioskDevices::class)->issueEnrolmentCode($this->kiosk);

        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get('/kiosk')->assertRedirect(route('kiosk.enrol'));
    }

    public function test_enrolment_attempts_are_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/kiosk/enrol', ['code' => 'NOPE-NOPE']);
        }

        $this->post('/kiosk/enrol', ['code' => 'NOPE-NOPE'])->assertStatus(429);
    }
}
