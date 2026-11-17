<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Tests\Feature\Fork;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Models\DataRetentionRun;
use App\Models\Site;
use App\Models\User;
use App\Settings\SettingsRepository;
use App\Support\VisitorPortalPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_events_are_append_only(): void
    {
        $event = app(AuditLogger::class)->record('test.action');

        try {
            $event->update(['action' => 'tampered']);
            $this->fail('Audit events must not be updatable.');
        } catch (LogicException) {
        }

        try {
            $event->delete();
            $this->fail('Audit events must not be deletable.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('audit_events', ['id' => $event->getKey(), 'action' => 'test.action']);
    }

    public function test_recording_records_actor_site_subject_and_hashed_ip(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();
        $this->actingAs($user);

        $event = app(AuditLogger::class)->record('test.action', subject: $site, site: $site, context: ['k' => 'v']);

        $this->assertSame($user->getKey(), $event->actor_user_id);
        $this->assertSame($site->getKey(), $event->site_id);
        $this->assertSame('Site', $event->subject_type);
        $this->assertSame((string) $site->getKey(), $event->subject_id);
        $this->assertSame(['k' => 'v'], $event->context);
        $this->assertNotNull($event->ip_hash);
        $this->assertNotSame('127.0.0.1', $event->ip_hash);
    }

    public function test_an_audit_failure_never_breaks_the_audited_action(): void
    {
        Schema::drop('audit_events');

        $this->assertNull(app(AuditLogger::class)->record('test.action'));

        // A settings save still succeeds even though its audit entry cannot be written.
        app(SettingsRepository::class)->saveOrganisation(['branding.name' => 'Still saved'], null);
        $this->assertSame('Still saved', config('branding.name'));
    }

    public function test_site_changes_are_audited(): void
    {
        $site = Site::factory()->create();
        $site->update(['name' => 'Renamed campus']);
        $site->delete();

        $this->assertSame(
            ['site.created', 'site.updated', 'site.deleted'],
            AuditEvent::query()->where('subject_type', 'Site')->where('subject_id', (string) $site->getKey())->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(['name'], AuditEvent::query()->where('action', 'site.updated')->sole()->context['fields']);
    }

    public function test_completed_visit_retention_runs_are_audited(): void
    {
        $this->artisan('visits:purge-expired')->assertSuccessful();

        $event = AuditEvent::query()->where('action', 'retention.visits.purged')->sole();

        $this->assertSame(AuditEvent::RESULT_SUCCESS, $event->result);
        $this->assertSame(DataRetentionRun::query()->latest('id')->value('id'), (int) $event->subject_id);
    }

    public function test_audit_purge_removes_only_expired_events_and_records_itself(): void
    {
        config(['organisation.audit_retention_days' => 30]);

        AuditEvent::query()->create(['occurred_at' => now()->subDays(31), 'action' => 'old', 'result' => 'success', 'channel' => 'web']);
        AuditEvent::query()->create(['occurred_at' => now()->subDays(29), 'action' => 'recent', 'result' => 'success', 'channel' => 'web']);

        $this->artisan('visitorportal:audit:purge')->assertSuccessful();

        $this->assertDatabaseMissing('audit_events', ['action' => 'old']);
        $this->assertDatabaseHas('audit_events', ['action' => 'recent']);

        $purge = AuditEvent::query()->where('action', 'retention.audit.purged')->sole();
        $this->assertSame(1, $purge->context['deleted']);
    }

    public function test_audit_purge_dry_run_deletes_nothing(): void
    {
        config(['organisation.audit_retention_days' => 30]);
        AuditEvent::query()->create(['occurred_at' => now()->subDays(400), 'action' => 'old', 'result' => 'success', 'channel' => 'web']);

        $this->artisan('visitorportal:audit:purge', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('audit_events', ['action' => 'old']);
    }

    public function test_admin_can_browse_the_audit_log_and_receptionist_cannot(): void
    {
        VisitorPortalPermissions::sync();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $receptionist = User::factory()->create();
        $receptionist->assignRole('receptionist');

        $event = app(AuditLogger::class)->record('settings.organisation.updated', context: ['changes' => []]);

        Livewire::actingAs($admin)->test(ListAuditEvents::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$event]);
        Livewire::actingAs($admin)->test(ViewAuditEvent::class, ['record' => $event->getKey()])->assertOk();

        Livewire::actingAs($receptionist)->test(ListAuditEvents::class)->assertForbidden();
    }
}
