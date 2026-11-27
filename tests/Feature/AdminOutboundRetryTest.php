<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\FreshdeskOutboundOperation;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOutboundRetryTest extends TestCase
{
    use RefreshDatabase;

    private const TICKET_ID = 90400;

    protected function setUp(): void
    {
        parent::setUp();

        Ticket::create([
            'ticket_id' => self::TICKET_ID,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-11-20T08:00:00Z',
        ]);
    }

    public function test_viewer_cannot_open_or_use_outbound_page(): void
    {
        $failed = $this->operation('sync_sla', 'failed');

        $this->asAdmin(AdminUser::ROLE_VIEWER)->get('/admin/outbound-operations')->assertForbidden();
        $this->asAdmin(AdminUser::ROLE_VIEWER)
            ->post('/admin/outbound-operations/tickets/'.self::TICKET_ID.'/resync')
            ->assertForbidden();

        $this->assertSame('failed', $failed->fresh()->state);
        $this->assertSame(1, FreshdeskOutboundOperation::query()->count());
    }

    public function test_manager_sees_failed_operations(): void
    {
        $this->operation('sync_sla', 'failed', ['last_error' => 'Freshdesk sync failed (status=405)']);

        $this->asAdmin(AdminUser::ROLE_SLA_MANAGER)
            ->get('/admin/outbound-operations')
            ->assertOk()
            ->assertSee((string) self::TICKET_ID)
            ->assertSee('status=405')
            ->assertSee('Sync lại ticket');
    }

    public function test_resync_creates_one_new_sync_and_supersedes_failed_copy(): void
    {
        $failed = $this->operation('sync_sla', 'failed');

        $this->asAdmin(AdminUser::ROLE_SLA_MANAGER)
            ->from('/admin/outbound-operations')
            ->post('/admin/outbound-operations/tickets/'.self::TICKET_ID.'/resync')
            ->assertRedirect('/admin/outbound-operations')
            ->assertSessionHas('success');

        $this->assertSame('superseded', $failed->fresh()->state);
        $this->assertSame(1, FreshdeskOutboundOperation::query()
            ->where('operation_type', 'sync_sla')->where('state', 'ready')->count());
        $this->assertTrue(AdminAuditLog::query()
            ->where('action', 'outbound.resync_ticket')
            ->where('entity_id', self::TICKET_ID)
            ->exists());
    }

    public function test_resync_unknown_ticket_is_rejected(): void
    {
        $this->asAdmin(AdminUser::ROLE_SUPER_ADMIN)
            ->post('/admin/outbound-operations/tickets/12345/resync')
            ->assertSessionHas('error');

        $this->assertSame(0, FreshdeskOutboundOperation::query()->count());
    }

    public function test_retry_command_creates_copy_and_keeps_failed_row(): void
    {
        $failed = $this->operation('change_due_date', 'failed', [
            'payload' => ['new_due_date' => '2026-12-01T08:00:00Z'],
        ]);

        $this->asAdmin(AdminUser::ROLE_SLA_MANAGER)
            ->post("/admin/outbound-operations/{$failed->operation_id}/retry")
            ->assertSessionHas('success');

        $this->assertSame('failed', $failed->fresh()->state);
        $copy = FreshdeskOutboundOperation::query()
            ->where('operation_id', '!=', $failed->operation_id)
            ->firstOrFail();
        $this->assertSame('ready', $copy->state);
        $this->assertSame('change_due_date', $copy->operation_type);
        $this->assertSame('2026-12-01T08:00:00Z', $copy->payload['new_due_date']);
        $this->assertTrue(AdminAuditLog::query()->where('action', 'outbound.retry_command')->exists());
    }

    public function test_retry_command_is_refused_when_newer_command_exists(): void
    {
        $failed = $this->operation('change_due_date', 'failed');
        FreshdeskOutboundOperation::query()
            ->whereKey($failed->operation_id)
            ->update(['created_at' => now()->subHour()]);
        $this->operation('change_due_date', 'completed');

        $this->asAdmin(AdminUser::ROLE_SLA_MANAGER)
            ->post("/admin/outbound-operations/{$failed->operation_id}/retry")
            ->assertSessionHas('error');

        $this->assertSame(2, FreshdeskOutboundOperation::query()->count());
    }

    public function test_older_command_is_refused_and_hidden_when_newer_one_also_failed(): void
    {
        $older = $this->operation('change_due_date', 'failed', ['last_error' => 'older-due-error']);
        FreshdeskOutboundOperation::query()
            ->whereKey($older->operation_id)
            ->update(['created_at' => now()->subDays(22)]);
        $newer = $this->operation('change_due_date', 'failed', ['last_error' => 'newer-due-error']);

        $page = $this->asAdmin(AdminUser::ROLE_SLA_MANAGER)->get('/admin/outbound-operations')->assertOk();
        $page->assertSee(route('admin.outbound.retry', $newer->operation_id), false);
        $page->assertDontSee(route('admin.outbound.retry', $older->operation_id), false);
        $page->assertSee('Đã có lệnh mới hơn');

        $this->asAdmin(AdminUser::ROLE_SLA_MANAGER)
            ->post("/admin/outbound-operations/{$older->operation_id}/retry")
            ->assertSessionHas('error');
        $this->assertSame(2, FreshdeskOutboundOperation::query()->count());
    }

    public function test_command_resyncs_single_ticket(): void
    {
        $this->operation('sync_sla', 'failed');

        $this->artisan('outbound:retry-failed', ['--ticket' => self::TICKET_ID])->assertSuccessful();

        $this->assertSame(1, FreshdeskOutboundOperation::query()
            ->where('operation_type', 'sync_sla')->where('state', 'ready')->count());
    }

    private function asAdmin(string $role): self
    {
        $admin = AdminUser::create([
            'name' => "QA {$role}",
            'username' => "qa_{$role}_".Str::random(6),
            'email' => Str::random(8).'@example.test',
            'password' => 'secret-password',
            'role' => $role,
            'is_active' => true,
        ]);

        return $this->withSession([
            'admin_logged_in' => true,
            'admin_user_id' => $admin->id,
        ]);
    }

    private function operation(string $type, string $state, array $overrides = []): FreshdeskOutboundOperation
    {
        return FreshdeskOutboundOperation::create(array_merge([
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => "{$type}:admin-test:".Str::uuid(),
            'ticket_id' => self::TICKET_ID,
            'operation_type' => $type,
            'coalesce_key' => $type,
            'generation' => 0,
            'sync_epoch' => 0,
            'operation_version' => 1,
            'payload' => [],
            'state' => $state,
            'available_at' => now(),
            'completed_at' => $state === 'ready' ? null : now(),
        ], $overrides));
    }
}
