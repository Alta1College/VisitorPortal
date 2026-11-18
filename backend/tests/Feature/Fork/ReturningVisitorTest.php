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
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\WorkflowRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ReturningVisitorTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Site $site;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        Storage::fake(WorkflowRecorder::EVIDENCE_DISK);
        $this->site = Site::factory()->create();
        $reception = User::factory()->create(['site_id' => $this->site->getKey(), 'is_active' => true]);
        app(SettingsRepository::class)->saveSite($this->site, ['organisation.reception_host_user_id' => $reception->getKey()], null);
        $type = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor', 'host_requirement' => VisitorType::HOST_NONE,
            'detail_fields' => ['email' => ['mode' => 'optional'], 'phone' => ['mode' => 'required'], 'company' => ['mode' => 'required']]]);

        // Remembered: WWCC number and photo. Asked every time: reason for visit.
        foreach ([
            ['key' => 'wwcc', 'label' => 'WWCC number', 'type' => FormField::TYPE_SHORT_TEXT, 'remember_for_returning' => true],
            ['key' => 'reason', 'label' => 'Reason for visit', 'type' => FormField::TYPE_SHORT_TEXT],
            ['key' => 'photo', 'label' => 'Photo', 'type' => FormField::TYPE_PHOTO, 'remember_for_returning' => true],
        ] as $order => $attributes) {
            $field = FormField::query()->create($attributes);
            FormFieldAssignment::query()->create(['form_field_id' => $field->getKey(), 'visitor_type_id' => $type->getKey(), 'is_required' => true, 'sort_order' => $order]);
        }

        $kiosk = Kiosk::query()->create(['site_id' => $this->site->getKey(), 'name' => 'Front']);
        [, $this->secret] = app(KioskDevices::class)->enrol(app(KioskDevices::class)->issueEnrolmentCode($kiosk));
    }

    private function kiosk()
    {
        return Livewire::withCookie(KioskDevices::COOKIE, $this->secret)->test(KioskScreen::class);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('me.png', base64_decode(self::PNG_1X1));
    }

    /** First visit at the kiosk: everything typed, photo taken. */
    private function firstVisit(): Visitor
    {
        $this->kiosk()->call('chooseType', 'contractor')->assertSet('step', 'identify')
            ->set('lookupIdentifier', 'casey@spark.example')->call('lookupReturning')
            ->assertSet('step', 'details')->assertSet('details.email', 'casey@spark.example')
            ->set('details.first_name', 'Casey')->set('details.name', 'Contractor')
            ->set('details.phone', '0412 345 678')->set('details.company', 'Spark Electrical')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'questions')
            ->set('answers.wwcc', 'WWC-123456')->set('answers.reason', 'Wiring')->set('answers.photo', $this->photo())
            ->call('submitQuestions')->assertHasNoErrors()->assertSet('step', 'confirm')
            ->call('finish')->assertSet('step', 'done');

        return Visitor::query()->sole();
    }

    public function test_the_kiosk_loads_a_returning_record_with_photo_and_remembered_answers(): void
    {
        $visitor = $this->firstVisit();

        $screen = $this->kiosk()->call('chooseType', 'contractor')
            // Mobile number written another way: an exact match after normalising.
            ->set('lookupIdentifier', '+61 412 345 678')->call('lookupReturning')
            ->assertSet('step', 'details')
            ->assertSee(__('Willkommen zurück, :name!', ['name' => 'Casey']))
            ->assertSee('casey@spark.example')->assertSee('Spark Electrical')
            ->assertSee('kiosk/returning-photo', false)
            ->call('confirmReturning')
            ->assertSet('details.phone', '0412 345 678')->assertSet('details.company', 'Spark Electrical')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'questions')
            // WWCC is remembered; the reason is asked again; the photo is reused.
            ->assertSet('answers.wwcc', 'WWC-123456')->assertSet('answers.reason', null)
            ->assertSet('savedAnswerKeys', ['photo'])
            ->call('submitQuestions')->assertHasErrors('answers.reason')
            ->set('answers.reason', 'Testing alarms')
            ->call('submitQuestions')->assertHasNoErrors()->assertSet('step', 'confirm')
            ->call('finish')->assertSet('step', 'done');

        $this->assertSame(1, Visitor::query()->count());
        $this->assertSame(2, DB::table('visit_visitor')->where('visitor_id', $visitor->getKey())->count());

        // The second visit keeps its own copy of the photo (evidence is purged per visit).
        $photos = VisitResponse::query()->where('field_key', 'photo')->orderBy('id')->pluck('file_path');
        $this->assertCount(2, $photos);
        $this->assertNotSame($photos[0], $photos[1]);
        Storage::disk(WorkflowRecorder::EVIDENCE_DISK)->assertExists($photos[1]);
        $this->assertSame('Testing alarms', VisitResponse::query()->where('field_key', 'reason')->latest('id')->value('value'));
    }

    public function test_no_match_carries_the_value_into_the_form_and_reveals_nothing(): void
    {
        $this->firstVisit();

        $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', '345 678')->call('lookupReturning')
            ->assertSet('step', 'details')->assertSet('pendingReturningToken', null)
            ->assertSet('details.phone', '345 678')->assertDontSee('Casey');

        $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', 'casey@spark')->call('lookupReturning')
            ->assertSet('pendingReturningToken', null)->assertSet('details.email', 'casey@spark');
    }

    public function test_a_forgotten_sign_in_is_closed_and_typed_values_update_the_record(): void
    {
        $visitor = $this->firstVisit();

        $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', 'Casey@Spark.example')->call('lookupReturning')->call('confirmReturning')
            ->set('details.company', 'Bright Sparks')
            ->call('submitDetails')->set('answers.reason', 'Again')->call('submitQuestions')
            ->call('finish')->assertSet('step', 'done');

        $this->assertSame('Bright Sparks', $visitor->fresh()->company);
        $rows = DB::table('visit_visitor')->orderBy('checked_in_at')->orderBy('visit_id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->checked_out_at, 'the earlier, forgotten sign-in is closed');
        $this->assertNull($rows[1]->checked_out_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'public.arrival.closed_previous']);
    }

    public function test_declining_starts_fresh_and_the_feature_can_be_turned_off(): void
    {
        $this->firstVisit();

        $this->kiosk()->call('chooseType', 'contractor')
            ->set('lookupIdentifier', 'casey@spark.example')->call('lookupReturning')->call('declineReturning')
            ->assertSet('pendingReturningToken', null)->assertSet('returningToken', null)
            ->assertSet('details.email', null)
            ->assertDontSee(__('Willkommen zurück, :name!', ['name' => 'Casey']));

        app(SettingsRepository::class)->saveOrganisation(['organisation.visitor_experience.returning_visitors' => '0'], null);

        $this->kiosk()->call('chooseType', 'contractor')->assertSet('step', 'details')
            ->call('lookupReturning')->assertNotFound();
    }

    public function test_a_phone_uses_the_record_without_showing_it(): void
    {
        $this->firstVisit();
        $link = app(SiteQrLinks::class)->forSite($this->site);

        $screen = Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->assertSet('step', 'identify')
            ->set('lookupIdentifier', 'casey@spark.example')->call('lookupReturning')
            ->assertSee(__('Willkommen zurück, :name!', ['name' => 'Casey']))
            ->assertSee('c•••@s•••.example')->assertSee('•••• 678')
            ->assertDontSee('Contractor')->assertDontSee('returning-photo')
            ->assertSee(__('Das bin ich nicht'))
            ->call('confirmReturning')
            ->call('submitDetails')->assertHasNoErrors()->assertSet('step', 'questions')
            ->assertSet('answers', [])->assertSet('savedAnswerKeys', ['wwcc', 'photo']);

        $snapshot = json_encode($screen->snapshot['data']);
        foreach (['casey@spark.example', '345', 'Spark', 'WWC-123456', 'Contractor'] as $secret) {
            $this->assertStringNotContainsString($secret, $snapshot);
        }

        $screen->set('answers.reason', 'Phone visit')->call('submitQuestions')->assertHasNoErrors()
            ->call('finish')->assertSet('step', 'done');

        $this->assertSame(1, Visitor::query()->count());
        $this->assertSame(2, VisitResponse::query()->where('field_key', 'wwcc')->count());
    }

    public function test_the_phone_keeps_nothing_and_always_looks_up_the_database(): void
    {
        $link = app(SiteQrLinks::class)->forSite($this->site);

        Livewire::test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->call('lookupReturning')
            ->set('details.first_name', 'Robin')->set('details.name', 'Phone')
            ->set('details.phone', '0499 111 222')->set('details.company', 'Acme')
            ->call('submitDetails')
            ->set('answers.wwcc', 'W-1')->set('answers.reason', 'R')->set('answers.photo', $this->photo())
            ->call('submitQuestions')->call('finish')->assertSet('step', 'done');

        // Only the sign-out cookie of the open visit: no saved details on the phone.
        $this->assertSame([MobileSignIn::DEPARTURE_COOKIE], array_values(array_map(fn ($cookie) => $cookie->getName(), Cookie::getQueuedCookies())));

        $departure = Cookie::queued(MobileSignIn::DEPARTURE_COOKIE)->getValue();
        Livewire::withCookie(MobileSignIn::DEPARTURE_COOKIE, $departure)->test(MobileSignIn::class, ['token' => $link->token])
            ->call('chooseType', 'contractor')->assertSet('step', 'identify')->assertSet('pendingReturningToken', null)
            ->set('lookupIdentifier', '0499111222')->call('lookupReturning')
            ->assertSee(__('Willkommen zurück, :name!', ['name' => 'Robin']))->assertSee('•••• 222')
            // Someone else on a borrowed phone: back to the lookup, nothing kept.
            ->call('declineReturning')->assertSet('pendingReturningToken', null)->assertSet('step', 'identify');
    }

    public function test_the_saved_photo_is_served_only_to_kiosks_of_the_site(): void
    {
        $this->firstVisit();
        $path = VisitResponse::query()->where('field_key', 'photo')->value('file_path');
        $returning = app(ReturningVisitors::class);
        $url = route('kiosk.returning-photo', ['token' => $returning->photoToken($path, $this->site->getKey())]);

        $this->get($url)->assertRedirect(route('kiosk.enrol'));
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $otherSite = route('kiosk.returning-photo', ['token' => $returning->photoToken($path, $this->site->getKey() + 1)]);
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get($otherSite)->assertNotFound();
        $this->withCookie(KioskDevices::COOKIE, $this->secret)->get(route('kiosk.returning-photo', ['token' => 'nope']))->assertNotFound();
    }

    public function test_masks(): void
    {
        $this->assertSame('c•••@s•••.example', ReturningVisitors::maskEmail('casey@spark.example'));
        $this->assertSame('•••• 678', ReturningVisitors::maskPhone('0412 345 678'));
        $this->assertNull(ReturningVisitors::maskPhone('123'));
    }
}
