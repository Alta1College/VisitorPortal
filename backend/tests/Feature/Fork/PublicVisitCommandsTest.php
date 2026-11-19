<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditEvent;
use App\HostDirectory\HostDirectory;
use App\Http\Middleware\EnsureRequiredMfaIsConfigured;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Notifications\Host\GuestCheckedInDatabaseNotification;
use App\PublicVisit\AttendanceTokens;
use App\PublicVisit\Channel;
use App\PublicVisit\Commands\ArrivalRequest;
use App\PublicVisit\Commands\RecordDeparture;
use App\PublicVisit\Commands\RegisterArrival;
use App\PublicVisit\PublicVisitServiceProvider;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyAssignment;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\PolicyPublisher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PublicVisitCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $host;

    private VisitorType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->host = User::factory()->create(['site_id' => $this->site->getKey(), 'first_name' => 'Hannah', 'name' => 'Host', 'is_active' => true]);
        $this->type = VisitorType::query()->create(['name' => 'Visitor', 'slug' => 'visitor']);
    }

    private function hostToken(?Site $site = null): string
    {
        return app(HostDirectory::class)->search($site ?? $this->site, 'Hann')[0]->token;
    }

    private function request(array $overrides = []): ArrivalRequest
    {
        $values = array_merge([
            'site' => $this->site,
            'channel' => Channel::Kiosk,
            'submissionKey' => (string) Str::uuid(),
            'visitorType' => $this->type,
            'details' => ['first_name' => 'Vera', 'name' => 'Visitor', 'email' => 'Vera@Example.test', 'phone' => null, 'company' => 'Acme'],
            'hostToken' => $this->hostToken(),
            'answers' => [],
            'acceptedPolicyVersionIds' => [],
        ], $overrides);

        return new ArrivalRequest(...$values);
    }

    private function attendance(int $visitId): object
    {
        return DB::table('visit_visitor')->where('visit_id', $visitId)->sole();
    }

    public function test_arrival_creates_a_checked_in_walk_in_without_any_staff_actor(): void
    {
        Notification::fake();

        $result = app(RegisterArrival::class)->handle($this->request());

        $visit = Visit::query()->findOrFail($result->visitId);
        $attendance = $this->attendance($visit->getKey());

        $this->assertTrue((bool) $visit->is_walk_in);
        $this->assertNull($visit->created_by_user_id);
        $this->assertSame($this->host->getKey(), $visit->host_user_id);
        $this->assertNotNull($attendance->checked_in_at);
        $this->assertNull($attendance->checked_in_by_user_id);
        $this->assertSame('kiosk', $attendance->checked_in_via);
        $this->assertSame($this->type->getKey(), $attendance->visitor_type_id);
        $this->assertSame('vera@example.test', DB::table('visitors')->where('id', $result->visitorId)->value('email'));

        $this->assertSame(40, strlen($result->departureToken));
        $this->assertSame('Vera', $result->visitorFirstName);
        $this->assertSame('Hannah Host', $result->hostDisplayName);
        $this->assertFalse($result->replayed);

        Notification::assertSentTo($this->host, GuestCheckedInDatabaseNotification::class);
        $this->assertDatabaseHas('audit_events', ['action' => 'public.arrival.registered', 'site_id' => $this->site->getKey()]);
    }

    public function test_arrival_records_the_configured_workflow_and_enforces_policies(): void
    {
        Notification::fake();
        $field = FormField::query()->create(['key' => 'company_badge', 'label' => 'Badge number', 'type' => FormField::TYPE_SHORT_TEXT]);
        FormFieldAssignment::query()->create(['form_field_id' => $field->getKey(), 'is_required' => true]);
        $policy = Policy::query()->create(['name' => 'Safety']);
        PolicyAssignment::query()->create(['policy_id' => $policy->getKey()]);
        $version = app(PolicyPublisher::class)->publish($policy, 'Safety', 'Be safe.', 'I agree', null);

        try {
            app(RegisterArrival::class)->handle($this->request(['answers' => [], 'acceptedPolicyVersionIds' => []]));
            $this->fail('Expected workflow validation to fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('answers.company_badge', $e->errors());
            $this->assertArrayHasKey('policies.'.$policy->getKey(), $e->errors());
        }

        $this->assertSame(0, Visit::query()->count(), 'Nothing is created when validation fails.');

        $result = app(RegisterArrival::class)->handle($this->request([
            'answers' => ['company_badge' => 'B-17'],
            'acceptedPolicyVersionIds' => [$version->getKey()],
        ]));

        $this->assertDatabaseHas('visit_responses', ['visit_id' => $result->visitId, 'field_key' => 'company_badge', 'value' => 'B-17']);
        $this->assertDatabaseHas('policy_acceptances', ['visit_id' => $result->visitId, 'policy_version_id' => $version->getKey(), 'channel' => 'kiosk']);
    }

    public function test_a_retried_submission_returns_the_first_result_without_a_second_visit(): void
    {
        Notification::fake();
        $key = (string) Str::uuid();

        $first = app(RegisterArrival::class)->handle($this->request(['submissionKey' => $key]));
        $second = app(RegisterArrival::class)->handle($this->request(['submissionKey' => $key]));

        $this->assertSame(1, Visit::query()->count());
        $this->assertSame($first->visitId, $second->visitId);
        $this->assertTrue($second->replayed);
        $this->assertNotSame($first->departureToken, $second->departureToken);

        // The same key from another site is a different submission.
        $otherSite = Site::factory()->create();
        $this->host->sites()->attach($otherSite);
        app(RegisterArrival::class)->handle($this->request(['site' => $otherSite, 'submissionKey' => $key, 'hostToken' => $this->hostToken($otherSite)]));
        $this->assertSame(2, Visit::query()->count());
    }

    public function test_malformed_submission_keys_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(RegisterArrival::class)->handle($this->request(['submissionKey' => 'x']));
    }

    public function test_host_rules_follow_the_visitor_type(): void
    {
        Notification::fake();
        $reception = User::factory()->create(['site_id' => $this->site->getKey(), 'first_name' => 'Front', 'name' => 'Desk', 'is_active' => true]);

        // Required: no token is an error.
        try {
            app(RegisterArrival::class)->handle($this->request(['hostToken' => null]));
            $this->fail('Host should be required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('host', $e->errors());
        }

        // None/optional without a token: the site's reception contact, once configured.
        $delivery = VisitorType::query()->create(['name' => 'Delivery', 'slug' => 'delivery', 'host_requirement' => VisitorType::HOST_NONE]);

        try {
            app(RegisterArrival::class)->handle($this->request(['visitorType' => $delivery, 'hostToken' => null]));
            $this->fail('Missing reception contact should block sign-in.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('host', $e->errors());
        }

        app(SettingsRepository::class)->saveSite($this->site, ['organisation.reception_host_user_id' => $reception->getKey()], null);

        $result = app(RegisterArrival::class)->handle($this->request(['visitorType' => $delivery, 'hostToken' => $this->hostToken()]));
        $this->assertSame($reception->getKey(), Visit::query()->find($result->visitId)->host_user_id, 'A host token is ignored for "no host" types.');
    }

    public function test_tampered_expired_or_foreign_host_tokens_are_rejected(): void
    {
        $directory = app(HostDirectory::class);
        $other = Site::factory()->create();

        $this->assertNull($directory->resolve($this->site, 'not-a-token'));
        $this->assertNull($directory->resolve($other, $this->hostToken()));

        $expired = Crypt::encryptString(json_encode(['u' => $this->host->getKey(), 's' => $this->site->getKey(), 'e' => now()->subMinute()->getTimestamp()]));
        $this->assertNull($directory->resolve($this->site, $expired));

        $token = $this->hostToken();
        $this->host->update(['is_active' => false]);
        $this->assertNull($directory->resolve($this->site, $token), 'Deactivated after the search.');
    }

    public function test_host_search_discloses_only_names_of_hosts_at_the_site(): void
    {
        VisitorPortalPermissions::sync();
        User::factory()->create(['site_id' => $this->site->getKey(), 'first_name' => 'Hanna', 'name' => 'Inactive', 'is_active' => false]);
        User::factory()->create(['site_id' => Site::factory()->create()->getKey(), 'first_name' => 'Hans', 'name' => 'Elsewhere', 'is_active' => true]);
        $monitor = User::factory()->create(['site_id' => $this->site->getKey(), 'first_name' => 'Hall', 'name' => 'Screen', 'is_active' => true]);
        $monitor->assignRole('welcome monitor');
        foreach (range(1, 8) as $i) {
            User::factory()->create(['site_id' => $this->site->getKey(), 'first_name' => 'Harper'.$i, 'name' => 'Staff', 'is_active' => true]);
        }

        $directory = app(HostDirectory::class);

        $this->assertSame([], $directory->search($this->site, 'H'), 'Too short to search.');
        $this->assertSame([], $directory->search($this->site, '%'), 'Wildcards are literal.');

        $results = $directory->search($this->site, 'Ha');
        $names = array_map(fn ($o) => $o->displayName, $results);

        $this->assertCount(5, $results);
        $this->assertContains('Hannah Host', $names);
        $this->assertNotContains('Hanna Inactive', $names);
        $this->assertNotContains('Hans Elsewhere', $names);
        $this->assertNotContains('Hall Screen', $names);
        $this->assertSame(['Hannah Host'], array_map(fn ($o) => $o->displayName, $directory->search($this->site, 'Hannah H')));
        $this->assertStringNotContainsString((string) $this->host->getKey(), json_encode(array_map(fn ($o) => $o->displayName, $results)));
    }

    public function test_a_failed_host_notification_does_not_undo_the_sign_in(): void
    {
        Event::listen(NotificationSending::class, fn () => throw new RuntimeException('mail server down'));

        $result = app(RegisterArrival::class)->handle($this->request());

        $this->assertNotNull($this->attendance($result->visitId)->checked_in_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'public.arrival.notification_failed', 'result' => AuditEvent::RESULT_FAILURE]);
    }

    public function test_departure_token_signs_out_once_and_is_then_useless(): void
    {
        Notification::fake();
        $arrival = app(RegisterArrival::class)->handle($this->request());

        $this->assertSame('Vera', app(RecordDeparture::class)->handle($arrival->departureToken, Channel::Kiosk));

        $attendance = $this->attendance($arrival->visitId);
        $this->assertNotNull($attendance->checked_out_at);
        $this->assertNull($attendance->checked_out_by_user_id);
        $this->assertSame('kiosk', $attendance->checked_out_via);

        $this->expectException(ValidationException::class);
        app(RecordDeparture::class)->handle($arrival->departureToken, Channel::Kiosk);
    }

    public function test_departure_rejects_unknown_expired_and_staff_closed_attendances_alike(): void
    {
        Notification::fake();
        $messages = [];
        $attempt = function (string $token) use (&$messages): void {
            try {
                app(RecordDeparture::class)->handle($token, Channel::EmailLink);
                $this->fail('Expected rejection.');
            } catch (ValidationException $e) {
                $messages[] = $e->errors()['token'][0];
            }
        };

        $attempt(Str::random(40));
        $attempt('short');

        $expired = app(RegisterArrival::class)->handle($this->request());
        DB::table('attendance_tokens')->where('visit_id', $expired->visitId)->update(['expires_at' => now()->subMinute()]);
        $attempt($expired->departureToken);

        // Staff already signed the visitor out at reception: the link is closed too.
        $closed = app(RegisterArrival::class)->handle($this->request());
        DB::table('visit_visitor')->where('visit_id', $closed->visitId)->update(['checked_out_at' => now()]);
        $attempt($closed->departureToken);
        $this->assertNotNull(DB::table('attendance_tokens')->where('visit_id', $closed->visitId)->value('used_at'));

        $this->assertCount(1, array_unique($messages), 'Every failure looks the same.');
        $this->assertSame(4, AuditEvent::query()->where('action', 'public.departure.rejected')->count());
    }

    public function test_tokens_are_stored_hashed(): void
    {
        Notification::fake();
        $arrival = app(RegisterArrival::class)->handle($this->request());

        $this->assertDatabaseMissing('attendance_tokens', ['token_hash' => $arrival->departureToken]);
        $this->assertDatabaseHas('attendance_tokens', ['token_hash' => AttendanceTokens::hash($arrival->departureToken)]);
    }

    public function test_contact_details_follow_the_contact_requirement(): void
    {
        config(['privacy.visitor_contact_requirement' => 'require_one']);

        try {
            app(RegisterArrival::class)->handle($this->request(['details' => ['first_name' => 'Vera', 'name' => 'Visitor', 'email' => ' ', 'phone' => null]]));
            $this->fail('Expected a contact detail to be required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
    }

    public function test_unavailable_visitor_types_are_refused(): void
    {
        $this->type->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        app(RegisterArrival::class)->handle($this->request());
    }

    public function test_public_middleware_group_has_no_staff_mfa_enforcement(): void
    {
        $group = app('router')->getMiddlewareGroups()[PublicVisitServiceProvider::MIDDLEWARE_GROUP];

        $this->assertNotEmpty($group);
        $this->assertNotContains(EnsureRequiredMfaIsConfigured::class, $group);
        $this->assertContains(ValidateCsrfToken::class, $group);
    }

    public function test_prune_removes_old_submission_keys_and_spent_tokens(): void
    {
        Notification::fake();
        $arrival = app(RegisterArrival::class)->handle($this->request());
        DB::table('public_submissions')->update(['created_at' => now()->subDays(3)]);
        DB::table('attendance_tokens')->update(['used_at' => now()->subDays(8)]);

        $this->artisan('visitorportal:public:prune')->assertSuccessful();

        $this->assertDatabaseCount('public_submissions', 0);
        $this->assertDatabaseCount('attendance_tokens', 0);
        $this->assertNotNull(Visit::query()->find($arrival->visitId), 'Visits are untouched.');
    }
}
