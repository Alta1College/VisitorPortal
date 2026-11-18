<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Kiosk\Kiosk;
use App\Kiosk\KioskDevices;
use App\Kiosk\Livewire\KioskScreen;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\PublicVisit\ReturningVisitors;
use App\QrCheckIn\Livewire\MobileSignIn;
use App\QrCheckIn\SiteQrLinks;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ReturningVisitorTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private string $secret;

    private Visitor $visitor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        $this->site = Site::factory()->create();
        $reception = User::factory()->create(['site_id' => $this->site->getKey(), 'is_active' => true]);
        app(SettingsRepository::class)->saveSite($this->site, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_NONE,
            'detail_fields' => ['phone' => ['mode' => 'required'], 'company' => ['mode' => 'required']]]);

        $kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front']);
        [, $this->secret] = app(KioskDevices::class)->enrol(app(KioskDevices::class)->issueEnrolmentCode($kiosk));

        $this->visitor = Visitor::query()->create([
            'first_name' => 'Casey', 'name' => 'Contractor', 'email' => 'casey@spark.example', 'phone' => '0412 345 678', 'company' => 'Spark Electrical',
        ]);
    }

    private function kiosk()
    {
        return Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class);
    }

    public function test_a_returning_visitor_reuses_their_record_without_the_screen_seeing_saved_values(): void
    {
        $screen = $this->kiosk()
            ->call('chooseType', 'contractor')
            ->call('startReturning')->assertSet('step', 'returning')
            // Wrong surname and partial number: one neutral message, nothing revealed.
            ->set('lookupIdentifier', '0412 345 678')->set('lookupLastName', 'Contract')
            ->call('lookupReturning')->assertHasErrors('lookup')->assertSet('pendingReturningToken', null)
            ->set('lookupIdentifier', '345 678')->set('lookupLastName', 'Contractor')
            ->call('lookupReturning')->assertHasErrors('lookup')
            // Same number written internationally, surname in another case.
            ->set('lookupIdentifier', '+61 412 345 678')->set('lookupLastName', 'CONTRACTOR')
            ->call('lookupReturning')->assertHasNoErrors()->assertSet('step', 'details')
            ->assertSee(__('Willkommen zurück, :name!', ['name' => 'Casey']))
            ->assertSee('c•••@s•••.example')->assertSee('•••• 678');

        $snapshot = json_encode($screen->snapshot['data']);
        $this->assertStringNotContainsString('casey@spark.example', $snapshot);
        $this->assertStringNotContainsString('345', $snapshot);
        $this->assertStringNotContainsString('Spark', $snapshot);

        $screen->call('confirmReturning')
            ->assertSet('details.first_name', 'Casey')->assertSet('details.phone', null)
            // Required phone and company come from the saved record on the server.
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'confirm')
            ->call('finish')->assertSet('step', 'done');

        $this->assertSame(1, Visitor::query()->count());
        $this->assertSame($this->visitor->getKey(), DB::table('visit_visitor')->sole()->visitor_id);
        $this->assertSame('0412 345 678', $this->visitor->fresh()->phone);
    }

    public function test_typed_values_update_the_saved_record_and_a_forgotten_sign_in_is_closed(): void
    {
        $first = $this->kiosk()->call('chooseType', 'contractor')->call('startReturning')
            ->set('lookupIdentifier', 'casey@spark.example')->set('lookupLastName', 'contractor')
            ->call('lookupReturning')->call('confirmReturning')
            ->call('submitDetails')->call('finish')->assertSet('step', 'done');

        $this->kiosk()->call('chooseType', 'contractor')->call('startReturning')
            ->set('lookupIdentifier', 'Casey@Spark.example')->set('lookupLastName', 'Contractor')
            ->call('lookupReturning')->call('confirmReturning')
            ->set('details.company', 'Bright Sparks')
            ->call('submitDetails')->call('finish')->assertSet('step', 'done');

        $this->assertSame('Bright Sparks', $this->visitor->fresh()->company);
        $rows = DB::table('visit_visitor')->orderBy('checked_in_at')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->checked_out_at, 'the earlier, forgotten sign-in is closed');
        $this->assertNull($rows[1]->checked_out_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'public.arrival.closed_previous']);
    }

    public function test_declining_starts_fresh_and_the_feature_can_be_turned_off(): void
    {
        $this->kiosk()->call('chooseType', 'contractor')->call('startReturning')
            ->set('lookupIdentifier', 'casey@spark.example')->set('lookupLastName', 'Contractor')
            ->call('lookupReturning')->call('declineReturning')
            ->assertSet('pendingReturningToken', null)->assertSet('returningToken', null)
            ->assertDontSee(__('Willkommen zurück, :name!', ['name' => 'Casey']));

        app(SettingsRepository::class)->saveOrganisation(['organisation.visitor_experience.returning_visitors' => '0'], null);

        $this->kiosk()->call('chooseType', 'contractor')
            ->assertDontSee(__('Schon einmal hier gewesen?'))
            ->call('startReturning')->assertNotFound();
    }

    public function test_a_phone_remembers_details_only_when_asked_and_can_forget_them(): void
    {
        $link = app(SiteQrLinks::class)->forSite($this->site);

        Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')
            ->set('details.first_name', 'Robin')->set('details.name', 'Phone')
            ->set('details.phone', '0499 111 222')->set('details.company', 'Acme')
            ->call('submitDetails')->set('rememberMe', true)->call('finish')->assertSet('step', 'done');

        $cookie = Cookie::queued(ReturningVisitors::REMEMBER_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());

        $screen = Livewire::withCookie(ReturningVisitors::REMEMBER_COOKIE, $cookie->getValue())->test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')
            ->assertSee(__('Willkommen zurück, :name!', ['name' => 'Robin']))->assertSee('•••• 222');

        $screen->call('confirmReturning')->call('submitDetails')->assertHasNoErrors()->call('finish')->assertSet('step', 'done');
        $this->assertSame(2, Visitor::query()->count(), 'Casey plus Robin, reused on the second visit');

        Livewire::withCookie(ReturningVisitors::REMEMBER_COOKIE, $cookie->getValue())->test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->call('forgetMe')
            ->assertSet('pendingReturningToken', null);
        $this->assertTrue(Cookie::queued(ReturningVisitors::REMEMBER_COOKIE)->getExpiresTime() < time());
    }

    public function test_phones_cannot_look_up_saved_details(): void
    {
        $link = app(SiteQrLinks::class)->forSite($this->site);

        Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')
            ->assertDontSee(__('Schon einmal hier gewesen?'));
    }

    public function test_masks(): void
    {
        $this->assertSame('c•••@s•••.example', ReturningVisitors::maskEmail('casey@spark.example'));
        $this->assertSame('•••• 678', ReturningVisitors::maskPhone('0412 345 678'));
        $this->assertNull(ReturningVisitors::maskPhone('123'));
    }
}
