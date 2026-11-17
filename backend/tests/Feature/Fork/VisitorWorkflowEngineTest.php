<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditEvent;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\FormFieldAssignment;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\PolicyAcceptance;
use App\VisitorWorkflow\Models\PolicyAssignment;
use App\VisitorWorkflow\Models\PolicyVersion;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\Models\VisitResponse;
use App\VisitorWorkflow\PolicyPublisher;
use App\VisitorWorkflow\ResponseValidator;
use App\VisitorWorkflow\WorkflowRecorder;
use App\VisitorWorkflow\WorkflowResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class VisitorWorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Site $site;

    private VisitorType $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        $this->contractor = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor']);
    }

    private function field(string $key, string $type, array $attributes = [], array $assignment = []): FormField
    {
        $field = FormField::query()->create(['key' => $key, 'label' => ucfirst($key), 'type' => $type, ...$attributes]);
        FormFieldAssignment::query()->create(['form_field_id' => $field->getKey(), ...$assignment]);

        return $field;
    }

    private function policy(string $name, array $assignment = []): PolicyVersion
    {
        $policy = Policy::query()->create(['name' => $name]);
        PolicyAssignment::query()->create(['policy_id' => $policy->getKey(), ...$assignment]);

        return app(PolicyPublisher::class)->publish($policy, $name, "Please read the {$name}.", 'I accept', null);
    }

    private function resolve(?VisitorType $type = null, ?Site $site = null)
    {
        return app(WorkflowResolver::class)->resolve($site ?? $this->site, $type ?? $this->contractor);
    }

    private function attendance(): array
    {
        $host = User::factory()->create(['site_id' => $this->site->getKey()]);
        $visit = Visit::factory()->create(['site_id' => $this->site->getKey(), 'host_user_id' => $host->getKey()]);
        $visitor = Visitor::factory()->create();
        $visit->visitors()->attach($visitor->getKey());

        return [$visit, $visitor];
    }

    public function test_types_can_be_limited_to_sites_and_deactivated(): void
    {
        $other = Site::factory()->create();
        $delivery = VisitorType::query()->create(['name' => 'Delivery', 'slug' => 'delivery']);
        $delivery->sites()->attach($other);

        $this->assertSame(['contractor'], VisitorType::query()->availableAt($this->site)->pluck('slug')->all());
        $this->assertEqualsCanonicalizing(['contractor', 'delivery'], VisitorType::query()->availableAt($other)->pluck('slug')->all());

        $this->contractor->update(['is_active' => false]);
        $this->expectException(InvalidArgumentException::class);
        $this->resolve();
    }

    public function test_most_specific_assignment_wins_and_fields_follow_sort_order(): void
    {
        $company = $this->field('company', FormField::TYPE_SHORT_TEXT, assignment: ['sort_order' => 20]);
        $this->field('licence', FormField::TYPE_SHORT_TEXT, assignment: ['visitor_type_id' => $this->contractor->getKey(), 'sort_order' => 10]);
        // This site makes the organisation-wide company field required and moves it first.
        FormFieldAssignment::query()->create(['form_field_id' => $company->getKey(), 'site_id' => $this->site->getKey(), 'is_required' => true, 'sort_order' => 5]);
        // Assigned only to another type: not asked.
        $parent = VisitorType::query()->create(['name' => 'Parent', 'slug' => 'parent']);
        $this->field('child_name', FormField::TYPE_SHORT_TEXT, assignment: ['visitor_type_id' => $parent->getKey()]);
        // Inactive fields are never asked.
        $this->field('old', FormField::TYPE_SHORT_TEXT, ['is_active' => false]);

        $definition = $this->resolve();

        $this->assertSame(['company', 'licence'], array_map(fn ($r) => $r->field->key, $definition->fields));
        $this->assertTrue($definition->field('company')->required);
        $this->assertFalse($definition->field('licence')->required);
    }

    public function test_policies_apply_by_organisation_site_and_type_using_the_current_version(): void
    {
        $org = $this->policy('Site safety');
        $this->policy('Contractor induction', ['visitor_type_id' => $this->contractor->getKey()]);
        $this->policy('Other campus rules', ['site_id' => Site::factory()->create()->getKey()]);
        $inactive = $this->policy('Retired');
        $inactive->policy->update(['is_active' => false]);

        $v2 = app(PolicyPublisher::class)->publish($org->policy, 'Site safety', 'Updated text.', 'I accept', null);

        $titles = array_map(fn (PolicyVersion $v) => $v->title, $this->resolve()->policies);

        $this->assertSame(['Site safety', 'Contractor induction'], $titles);
        $this->assertSame($v2->getKey(), $this->resolve()->policies[0]->getKey());
        $this->assertSame(2, $v2->version);
    }

    public function test_published_versions_are_immutable_and_identical_republishing_is_refused(): void
    {
        $version = $this->policy('Site safety');

        $this->expectException(LogicException::class);
        $version->update(['body' => 'rewritten history']);
    }

    public function test_republishing_identical_wording_is_refused(): void
    {
        $version = $this->policy('Site safety');

        $this->expectException(InvalidArgumentException::class);
        app(PolicyPublisher::class)->publish($version->policy, $version->title, $version->body, $version->acceptance_label, null);
    }

    public function test_conditional_fields_are_only_required_and_kept_when_visible(): void
    {
        $this->field('working_with_children', FormField::TYPE_YES_NO, assignment: ['is_required' => true, 'sort_order' => 1]);
        $this->field('card_number', FormField::TYPE_SHORT_TEXT, [
            'conditions' => ['match' => 'all', 'rules' => [['field' => 'working_with_children', 'operator' => 'equals', 'value' => '1']]],
        ], ['is_required' => true, 'sort_order' => 2]);

        $definition = $this->resolve();
        $validator = app(ResponseValidator::class);

        // Hidden: not required, and a submitted value is discarded.
        $result = $validator->validate($definition, ['working_with_children' => '0', 'card_number' => 'ignored'], []);
        $this->assertSame(['working_with_children' => '0'], $result->answers);

        // Visible: required.
        try {
            $validator->validate($definition, ['working_with_children' => '1'], []);
            $this->fail('Expected the visible conditional field to be required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('answers.card_number', $e->errors());
        }

        $result = $validator->validate($definition, ['working_with_children' => '1', 'card_number' => ' 12345 '], []);
        $this->assertSame(['working_with_children' => '1', 'card_number' => '12345'], $result->answers);
    }

    public function test_field_types_are_validated_server_side(): void
    {
        $this->field('email', FormField::TYPE_EMAIL);
        $this->field('trade', FormField::TYPE_DROPDOWN, ['options' => [['value' => 'electrician', 'label' => 'Electrician']]]);
        $this->field('expiry', FormField::TYPE_DATE, ['validation' => ['min' => 'today']]);
        $this->field('induction', FormField::TYPE_ACKNOWLEDGEMENT, assignment: ['is_required' => true]);
        $this->field('workers', FormField::TYPE_NUMBER, ['validation' => ['min' => 1, 'max' => 20]]);

        try {
            app(ResponseValidator::class)->validate($this->resolve(), [
                'email' => 'not-an-email',
                'trade' => 'astronaut',
                'expiry' => now()->subDay()->format('Y-m-d'),
                'induction' => '0',
                'workers' => 50,
            ], []);
            $this->fail('Expected validation errors.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(
                ['answers.email', 'answers.trade', 'answers.expiry', 'answers.induction', 'answers.workers'],
                array_keys($e->errors()),
            );
        }
    }

    public function test_every_applicable_policy_must_be_accepted(): void
    {
        $safety = $this->policy('Site safety');
        $induction = $this->policy('Contractor induction', ['visitor_type_id' => $this->contractor->getKey()]);

        try {
            app(ResponseValidator::class)->validate($this->resolve(), [], [$safety->getKey()]);
            $this->fail('Expected the unaccepted policy to be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(['policies.'.$induction->policy_id], array_keys($e->errors()));
        }

        // Accepting an older version id does not count.
        $newSafety = app(PolicyPublisher::class)->publish($safety->policy, 'Site safety', 'New text', 'I accept', null);
        $this->expectException(ValidationException::class);
        app(ResponseValidator::class)->validate($this->resolve(), [], [$safety->getKey(), $induction->getKey()]);
        unset($newSafety);
    }

    public function test_recording_stores_answers_type_acceptances_and_encrypts_sensitive_values(): void
    {
        Storage::fake('local');
        $this->field('company', FormField::TYPE_SHORT_TEXT, assignment: ['sort_order' => 1]);
        $this->field('card_number', FormField::TYPE_SHORT_TEXT, ['is_sensitive' => true], ['sort_order' => 2]);
        $this->field('photo', FormField::TYPE_PHOTO, assignment: ['sort_order' => 3]);
        $policy = $this->policy('Site safety');

        [$visit, $visitor] = $this->attendance();
        $definition = $this->resolve();
        $validated = app(ResponseValidator::class)->validate($definition, [
            'company' => 'Acme Electrical',
            'card_number' => 'WWC-0001',
            'photo' => UploadedFile::fake()->createWithContent('me.png', base64_decode(self::PNG_1X1)),
        ], [$policy->getKey()]);

        app(WorkflowRecorder::class)->record($visit, $visitor, $validated, 'kiosk');

        $this->assertSame($this->contractor->getKey(), (int) DB::table('visit_visitor')->where('visit_id', $visit->getKey())->value('visitor_type_id'));

        $card = VisitResponse::query()->where('field_key', 'card_number')->sole();
        $this->assertNotSame('WWC-0001', $card->value);
        $this->assertSame('WWC-0001', $card->plainValue());
        $this->assertSame('Acme Electrical', VisitResponse::query()->where('field_key', 'company')->value('value'));

        $photo = VisitResponse::query()->where('field_key', 'photo')->sole();
        $this->assertStringStartsWith('evidence/'.$visit->getKey().'/', $photo->file_path);
        Storage::disk('local')->assertExists($photo->file_path);

        $acceptance = PolicyAcceptance::query()->sole();
        $this->assertSame($policy->getKey(), $acceptance->policy_version_id);
        $this->assertSame('kiosk', $acceptance->channel);

        // The audit entry carries counts, never answer values.
        $audit = AuditEvent::query()->where('action', 'visit.workflow.recorded')->sole();
        $this->assertSame(3, $audit->context['answers']);
        $this->assertStringNotContainsString('WWC-0001', json_encode($audit->context));
    }

    public function test_history_survives_field_edits_and_new_policy_versions(): void
    {
        $field = $this->field('company', FormField::TYPE_SHORT_TEXT);
        $v1 = $this->policy('Site safety');
        [$visit, $visitor] = $this->attendance();

        app(WorkflowRecorder::class)->record($visit, $visitor,
            app(ResponseValidator::class)->validate($this->resolve(), ['company' => 'Acme'], [$v1->getKey()]), 'web');

        $field->update(['label' => 'Business name']);
        app(PolicyPublisher::class)->publish($v1->policy, 'Site safety', 'Completely new text', 'I accept', null);
        $field->delete();

        $response = VisitResponse::query()->sole();
        $this->assertSame('Company', $response->field_label);
        $this->assertNull($response->form_field_id);
        $this->assertSame('Please read the Site safety.', PolicyAcceptance::query()->sole()->policyVersion->body);
    }

    public function test_recording_rejects_a_visitor_not_on_the_visit_or_a_different_site(): void
    {
        [$visit] = $this->attendance();
        $stranger = Visitor::factory()->create();
        $validated = app(ResponseValidator::class)->validate($this->resolve(), [], []);

        $this->expectException(InvalidArgumentException::class);
        app(WorkflowRecorder::class)->record($visit, $stranger, $validated, 'web');
    }

    public function test_evidence_purge_removes_expired_and_orphaned_files_only(): void
    {
        Storage::fake('local');
        config(['organisation.evidence_retention_days' => 30]);
        [$visit, $visitor] = $this->attendance();

        Storage::disk('local')->put('evidence/1/old.png', 'x');
        Storage::disk('local')->put('evidence/1/recent.png', 'x');
        Storage::disk('local')->put('evidence/1/orphan.png', 'x');
        touch(Storage::disk('local')->path('evidence/1/orphan.png'), now()->subDays(2)->getTimestamp());
        Storage::disk('local')->put('evidence/1/uploading.png', 'x');

        $old = VisitResponse::query()->create(['visit_id' => $visit->getKey(), 'visitor_id' => $visitor->getKey(), 'field_key' => 'a', 'field_label' => 'A', 'field_type' => 'photo', 'file_path' => 'evidence/1/old.png']);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        VisitResponse::query()->create(['visit_id' => $visit->getKey(), 'visitor_id' => $visitor->getKey(), 'field_key' => 'b', 'field_label' => 'B', 'field_type' => 'photo', 'file_path' => 'evidence/1/recent.png']);

        $this->artisan('visitorportal:evidence:purge')->assertSuccessful();

        Storage::disk('local')->assertMissing('evidence/1/old.png');
        Storage::disk('local')->assertMissing('evidence/1/orphan.png');
        Storage::disk('local')->assertExists('evidence/1/recent.png');
        Storage::disk('local')->assertExists('evidence/1/uploading.png');
        $this->assertNull($old->fresh()->file_path);
        $this->assertDatabaseHas('audit_events', ['action' => 'retention.evidence.purged']);
    }

    public function test_configuration_changes_are_audited(): void
    {
        $this->contractor->update(['name' => 'Tradesperson']);
        $this->policy('Site safety');

        $actions = AuditEvent::query()->pluck('action')->all();

        $this->assertContains('workflow.visitor_type.created', $actions);
        $this->assertContains('workflow.visitor_type.updated', $actions);
        $this->assertContains('workflow.policy.created', $actions);
        $this->assertContains('workflow.policy.version_published', $actions);
    }
}
