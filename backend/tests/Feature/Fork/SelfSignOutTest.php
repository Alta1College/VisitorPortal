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
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\ArrivalRequest;
use App\PublicVisit\Commands\ArrivalResult;
use App\PublicVisit\Commands\RegisterArrival;
use App\PublicVisit\Mail\SignOutLinkMail;
use App\QrCheckIn\Livewire\MobileSignIn;
use App\QrCheckIn\SiteQrLinks;
use App\Settings\SettingsRepository;
use App\VisitorWorkflow\Models\VisitorType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SelfSignOutTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Kiosk $kiosk;

    private string $secret;

    private VisitorType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        $this->site = Site::factory()->create();
        $reception = User::factory()->create(['site_id' => $this->site->getKey(), 'is_active' => true]);
        app(SettingsRepository::class)->saveSite($this->site, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        $this->type = VisitorType::query()->create(['name' => 'Delivery', 'slug' => 'delivery', 'host_requirement' => VisitorType::HOST_NONE]);

        $this->kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front']);
        [, $this->secret] = app(KioskDevices::class)->enrol(app(KioskDevices::class)->issueEnrolmentCode($this->kiosk));
    }

    private function arrive(string $first = 'Pat', string $last = 'Parent', ?string $email = null, ?Site $site = null): ArrivalResult
    {
        return app(RegisterArrival::class)->handle(new ArrivalRequest(
            site: $site ?? $this->site,
            channel: Channel::Kiosk,
            submissionKey: (string) Str::uuid(),
            visitorType: $this->type,
            details: ['first_name' => $first, 'name' => $last, 'email' => $email],
            hostToken: null,
            answers: [],
            acceptedPolicyVersionIds: [],
        ));
    }

    private function attendance(ArrivalResult $result): object
    {
        return DB::table('visit_visitor')->where('visit_id', $result->visitId)->where('visitor_id', $result->visitorId)->sole();
    }

    private function kioskScreen()
    {
        return Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class);
    }

    public function test_an_unattended_kiosk_needs_the_exact_name(): void
    {
        $result = $this->arrive();

        $this->kioskScreen()
            ->assertSee(__('Besuch beenden'))
            ->call('startSignOut')->assertSet('step', 'signout')
            // Prefix search is a staffed-kiosk feature only.
            ->set('signOutQuery', 'Pa')->assertSet('signOutResults', [])
            ->set('signOutFirst', 'Pat')->set('signOutLast', 'Paren')
            ->call('findSignOut')->assertHasErrors('signout')->assertSet('step', 'signout')
            ->set('signOutLast', 'PARENT')
            ->call('findSignOut')->assertSet('step', 'signout_confirm')->assertSee('Pat P.')->assertDontSee('Parent')
            ->call('confirmSignOut')->assertSet('step', 'signed_out')->assertSee(__('Auf Wiedersehen, :name!', ['name' => 'Pat']));

        $attendance = $this->attendance($result);
        $this->assertNotNull($attendance->checked_out_at);
        $this->assertSame('kiosk', $attendance->checked_out_via);
        $this->assertSame(0, DB::table('attendance_tokens')->whereNull('used_at')->count());
    }

    public function test_a_staffed_kiosk_suggests_names_and_lists_duplicates(): void
    {
        $this->kiosk->update(['is_staffed' => true]);
        $first = $this->arrive();
        $this->travel(5)->minutes();
        $this->arrive();
        $this->arrive('Robin', 'Other');

        $screen = $this->kioskScreen()->call('startSignOut')
            ->set('signOutQuery', 'P')->assertSet('signOutResults', [])
            ->set('signOutQuery', 'Par');

        $this->assertCount(2, $screen->get('signOutResults'));
        $this->assertStringNotContainsString('"id"', json_encode($screen->snapshot['data']));

        $screen->call('chooseSignOut', 1)->call('confirmSignOut')->assertSet('step', 'signed_out');

        // Newest first: the second option is the earlier sign-in.
        $this->assertNotNull($this->attendance($first)->checked_out_at);
    }

    public function test_a_sign_out_option_from_another_site_or_already_closed_is_refused(): void
    {
        $otherSite = Site::factory()->create();
        $reception = User::factory()->create(['site_id' => $otherSite->getKey(), 'is_active' => true]);
        app(SettingsRepository::class)->saveSite($otherSite, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        $this->arrive(site: $otherSite);

        $this->kioskScreen()->call('startSignOut')
            ->set('signOutFirst', 'Pat')->set('signOutLast', 'Parent')
            ->call('findSignOut')->assertHasErrors('signout');

        $result = $this->arrive();
        $screen = $this->kioskScreen()->call('startSignOut')
            ->set('signOutFirst', 'Pat')->set('signOutLast', 'Parent')->call('findSignOut')->assertSet('step', 'signout_confirm');

        // Signed out elsewhere meanwhile (e.g. by reception): the stale option fails safely.
        DB::table('visit_visitor')->where('visit_id', $result->visitId)->update(['checked_out_at' => now()]);
        $screen->call('confirmSignOut')->assertSet('step', 'signout')->assertHasErrors('signout');
    }

    public function test_the_phone_offers_sign_out_for_its_own_visit(): void
    {
        $link = app(SiteQrLinks::class)->forSite($this->site);

        Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->assertDontSee(__('Besuch beenden'))
            ->call('chooseType', 'delivery')->call('lookupReturning')
            ->set('details.first_name', 'Pat')->set('details.name', 'Phone')
            ->call('submitDetails')->call('finish')->assertSet('step', 'done');

        $cookie = Cookie::queued(MobileSignIn::DEPARTURE_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());

        Livewire::withCookie(MobileSignIn::DEPARTURE_COOKIE, $cookie->getValue())->test(MobileSignIn::class, ['token' => $link->token])
            ->assertSee(__('Sie sind angemeldet, :name.', ['name' => 'Pat']))
            ->call('signOutHere')->assertSet('step', 'signed_out');

        $this->assertSame('qr', DB::table('visit_visitor')->sole()->checked_out_via);

        // Name lookup is a kiosk feature only (phones are internet-reachable).
        Livewire::test(MobileSignIn::class, ['token' => $link->token])->call('startSignOut')->assertNotFound();
    }

    public function test_an_emailed_link_signs_out_only_after_the_button_is_pressed(): void
    {
        $result = $this->arrive(email: 'pat@example.org');
        app()->terminate();

        $url = null;
        Mail::assertSent(SignOutLinkMail::class, function (SignOutLinkMail $mail) use (&$url): bool {
            $url = $mail->url;

            return $mail->hasTo('pat@example.org');
        });
        $path = parse_url($url, PHP_URL_PATH);

        // Opening the link (or a mail scanner fetching it) does not sign out.
        $this->get($path)->assertOk()->assertSee(__('Jetzt abmelden'));
        $this->assertNull($this->attendance($result)->checked_out_at);

        $this->post($path)->assertOk()->assertSee(__('Auf Wiedersehen, :name!', ['name' => 'Pat']));
        $this->assertSame('email_link', $this->attendance($result)->checked_out_via);

        $this->post($path)->assertOk()->assertSee(__('Vielleicht sind Sie bereits abgemeldet.'));
        $this->get('/leave/'.str_repeat('a', 40))->assertOk()->assertSee(__('Vielleicht sind Sie bereits abgemeldet.'));
    }

    public function test_no_email_when_the_setting_is_off_or_no_address_was_given(): void
    {
        $this->arrive();
        app(SettingsRepository::class)->saveOrganisation(['organisation.visitor_experience.email_sign_out_link' => '0'], null);
        $this->arrive('Sam', 'Mail', 'sam@example.org');
        app()->terminate();

        Mail::assertNothingSent();
    }
}
