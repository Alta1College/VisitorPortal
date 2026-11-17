<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Filament\Resources\FormFields\Pages\CreateFormField;
use App\Filament\Resources\FormFields\Pages\ListFormFields;
use App\Filament\Resources\Policies\Pages\CreatePolicy;
use App\Filament\Resources\Policies\Pages\EditPolicy;
use App\Filament\Resources\Policies\Pages\ListPolicies;
use App\Filament\Resources\Policies\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\VisitorTypes\Pages\CreateVisitorType;
use App\Filament\Resources\VisitorTypes\Pages\EditVisitorType;
use App\Filament\Resources\VisitorTypes\Pages\ListVisitorTypes;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Support\VisitorPortalPermissions;
use App\VisitorWorkflow\Models\FormField;
use App\VisitorWorkflow\Models\Policy;
use App\VisitorWorkflow\Models\VisitorType;
use App\VisitorWorkflow\PolicyPublisher;
use App\VisitorWorkflow\WorkflowResolver;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class VisitorWorkflowAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        VisitorPortalPermissions::sync();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_admin_can_create_a_visitor_type_limited_to_a_site(): void
    {
        $site = Site::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(CreateVisitorType::class)
            ->fillForm([
                'name' => 'Contractor',
                'slug' => 'contractor',
                'host_requirement' => VisitorType::HOST_OPTIONAL,
                'sites' => [$site->getKey()],
                'badge_color' => '#ff6600',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = VisitorType::query()->where('slug', 'contractor')->sole();
        $this->assertSame([$site->getKey()], $type->sites->modelKeys());
        $this->assertSame(VisitorType::HOST_OPTIONAL, $type->host_requirement);
    }

    public function test_admin_can_create_a_conditional_field_with_assignments(): void
    {
        $type = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor']);
        $site = Site::factory()->create();
        FormField::query()->create(['key' => 'works_with_children', 'label' => 'Will you work with children?', 'type' => FormField::TYPE_YES_NO])
            ->assignments()->create(['sort_order' => 1]);

        Livewire::actingAs($this->admin)
            ->test(CreateFormField::class)
            ->fillForm([
                'label' => 'Card number',
                'key' => 'card_number',
                'type' => FormField::TYPE_SHORT_TEXT,
                'is_sensitive' => true,
                'conditions' => [
                    'match' => 'all',
                    'rules' => [['field' => 'works_with_children', 'operator' => 'equals', 'value' => '1']],
                ],
                'assignments' => [
                    ['visitor_type_id' => $type->getKey(), 'site_id' => null, 'sort_order' => 2, 'is_required' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $field = FormField::query()->where('key', 'card_number')->sole();
        $this->assertTrue($field->is_sensitive);
        $this->assertSame('works_with_children', $field->conditions['rules'][0]['field']);

        $definition = app(WorkflowResolver::class)->resolve($site, $type);
        $this->assertSame(['works_with_children', 'card_number'], array_map(fn ($r) => $r->field->key, $definition->fields));
        $this->assertTrue($definition->field('card_number')->required);
    }

    public function test_field_keys_are_validated(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreateFormField::class)
            ->fillForm(['label' => 'X', 'key' => 'Bad Key!', 'type' => FormField::TYPE_SHORT_TEXT])
            ->call('create')
            ->assertHasFormErrors(['key']);
    }

    public function test_admin_can_create_a_policy_and_publish_a_new_version(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreatePolicy::class)
            ->fillForm([
                'name' => 'Site safety',
                'first_version' => [
                    'title' => 'Site safety rules',
                    'body' => 'Wear your badge at all times.',
                    'acceptance_label' => 'I agree',
                ],
                'assignments' => [['visitor_type_id' => null, 'site_id' => null]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $policy = Policy::query()->where('name', 'Site safety')->sole();
        $this->assertSame(1, $policy->currentVersion->version);
        $this->assertSame(1, $policy->assignments()->count());

        Livewire::actingAs($this->admin)
            ->test(VersionsRelationManager::class, ['ownerRecord' => $policy, 'pageClass' => EditPolicy::class])
            ->callTableAction('publish', data: [
                'title' => 'Site safety rules',
                'body' => 'Wear your badge at all times. Sign out when you leave.',
                'acceptance_label' => 'I agree',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, $policy->fresh()->currentVersion->version);
        $this->assertSame('Wear your badge at all times.', $policy->versions()->where('version', 1)->value('body'));
    }

    public function test_used_records_cannot_be_deleted(): void
    {
        $type = VisitorType::query()->create(['name' => 'Contractor', 'slug' => 'contractor']);
        $site = Site::factory()->create();
        $visit = Visit::factory()->create(['site_id' => $site->getKey(), 'host_user_id' => User::factory()->create(['site_id' => $site->getKey()])->getKey()]);
        $visit->visitors()->attach(Visitor::factory()->create()->getKey());
        DB::table('visit_visitor')->update(['visitor_type_id' => $type->getKey()]);

        Livewire::actingAs($this->admin)
            ->test(EditVisitorType::class, ['record' => $type->getKey()])
            ->assertActionHidden(DeleteAction::class);

        $policy = Policy::query()->create(['name' => 'Rules']);
        app(PolicyPublisher::class)->publish($policy, 'Rules', 'Text', 'OK', null);

        Livewire::actingAs($this->admin)
            ->test(EditPolicy::class, ['record' => $policy->getKey()])
            ->assertActionHidden(DeleteAction::class);
    }

    public function test_users_without_permission_cannot_manage_the_workflow(): void
    {
        $receptionist = User::factory()->create();
        $receptionist->assignRole('receptionist');

        Livewire::actingAs($receptionist)->test(ListVisitorTypes::class)->assertForbidden();
        Livewire::actingAs($receptionist)->test(ListFormFields::class)->assertForbidden();
        Livewire::actingAs($receptionist)->test(ListPolicies::class)->assertForbidden();
    }
}
