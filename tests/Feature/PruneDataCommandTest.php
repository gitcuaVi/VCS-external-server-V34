<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'retention.outbound_completed_days' => 30,
            'retention.outbound_failed_days' => 90,
            'retention.webhook_receipt_days' => 30,
            'rocketchat_audit.retention_days' => 90,
        ]);
    }

    public function test_prunes_finished_outbound_operations_after_their_retention(): void
    {
        $oldCompleted = $this->operation('completed', 31);
        $recentCompleted = $this->operation('completed', 29);
        $oldSuperseded = $this->operation('superseded', 31);
        $failedWithinWindow = $this->operation('failed', 89);
        $oldFailed = $this->operation('failed', 91);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertOperationsMissing([$oldCompleted, $oldSuperseded, $oldFailed]);
        $this->assertOperationsPresent([$recentCompleted, $failedWithinWindow]);
    }

    public function test_never_prunes_pending_work_or_overdue_scanner_markers(): void
    {
        $kept = [
            $this->operation('ready', 400),
            $this->operation('dispatched', 400),
            $this->operation('processing', 400),
            // The overdue scanner reads these rows to avoid re-flagging the same due date.
            $this->operation('completed', 400, ['source' => 'sla-overdue-scanner', 'metric_type' => 'ttr']),
        ];
        $prunable = $this->operation('completed', 400, ['source' => 'manual-resync']);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertOperationsPresent($kept);
        $this->assertOperationsMissing([$prunable]);
    }

    public function test_prunes_old_webhook_receipts_and_rocketchat_history(): void
    {
        $oldReceipt = $this->receipt(31);
        $recentReceipt = $this->receipt(29);
        $oldDelivery = $this->delivery(91);
        $recentDelivery = $this->delivery(89);

        $this->artisan('data:prune')->assertSuccessful();

        $this->assertDatabaseMissing('freshdesk_webhook_receipts', ['receipt_id' => $oldReceipt]);
        $this->assertDatabaseHas('freshdesk_webhook_receipts', ['receipt_id' => $recentReceipt]);
        $this->assertDatabaseMissing('rocket_chat_delivery_statuses', ['delivery_id' => $oldDelivery]);
        $this->assertDatabaseHas('rocket_chat_delivery_statuses', ['delivery_id' => $recentDelivery]);
    }

    public function test_dry_run_counts_without_deleting(): void
    {
        $operation = $this->operation('completed', 31);
        $receipt = $this->receipt(31);

        $this->artisan('data:prune --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertOperationsPresent([$operation]);
        $this->assertDatabaseHas('freshdesk_webhook_receipts', ['receipt_id' => $receipt]);
    }

    public function test_only_option_limits_the_pruned_tables(): void
    {
        $operation = $this->operation('completed', 31);
        $receipt = $this->receipt(31);

        $this->artisan('data:prune --only=receipts')->assertSuccessful();

        $this->assertOperationsPresent([$operation]);
        $this->assertDatabaseMissing('freshdesk_webhook_receipts', ['receipt_id' => $receipt]);
    }

    public function test_rejects_unknown_only_value(): void
    {
        $this->artisan('data:prune --only=tickets')->assertFailed();
    }

    public function test_legacy_rocketchat_command_still_prunes_history(): void
    {
        $oldDelivery = $this->delivery(91);
        $operation = $this->operation('completed', 31);

        $this->artisan('rocketchat-audit:prune')->assertSuccessful();

        $this->assertDatabaseMissing('rocket_chat_delivery_statuses', ['delivery_id' => $oldDelivery]);
        $this->assertOperationsPresent([$operation]);
    }

    public function test_runs_daily_from_the_scheduler(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'data:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('30 2 * * *', $events->first()->expression);
        $this->assertFalse(
            collect(app(Schedule::class)->events())
                ->contains(fn ($event): bool => str_contains((string) $event->command, 'rocketchat-audit:prune')),
            'The legacy prune command must not also be scheduled.'
        );
    }

    public function test_writes_one_summary_log_line(): void
    {
        $this->operation('completed', 31);
        Log::spy();

        $this->artisan('data:prune')->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context = []): bool => $message === 'Data prune completed'
                && ($context['outbound'] ?? null) === 1
                && ($context['dry_run'] ?? null) === false
        )->once();
    }

    private function operation(string $state, int $ageDays, ?array $payload = null): string
    {
        $id = (string) Str::uuid();
        $at = now()->subDays($ageDays);

        DB::table('freshdesk_outbound_operations')->insert([
            'operation_id' => $id,
            'idempotency_key' => 'prune-test:'.$id,
            'ticket_id' => 18000,
            'operation_type' => 'sync_sla',
            'coalesce_key' => 'sla-sync',
            'generation' => 1,
            'sync_epoch' => 0,
            'operation_version' => 1,
            'payload' => $payload === null ? null : json_encode($payload),
            'state' => $state,
            'available_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $id;
    }

    private function receipt(int $ageDays): string
    {
        $id = (string) Str::uuid();
        $at = now()->subDays($ageDays);

        DB::table('freshdesk_webhook_receipts')->insert([
            'receipt_id' => $id,
            'ticket_id' => 18000,
            'payload_checksum' => str_repeat('a', 64),
            'received_at' => $at,
            'committed_at' => $at,
        ]);

        return $id;
    }

    private function delivery(int $ageDays): string
    {
        $id = (string) Str::uuid();
        $at = now()->subDays($ageDays);

        DB::table('rocket_chat_delivery_statuses')->insert([
            'delivery_id' => $id,
            'event_code' => 'SYSTEM_ERROR',
            'status' => 'success',
            'attempt_count' => 1,
            'attempted_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $id;
    }

    private function assertOperationsMissing(array $ids): void
    {
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('freshdesk_outbound_operations', ['operation_id' => $id]);
        }
    }

    private function assertOperationsPresent(array $ids): void
    {
        foreach ($ids as $id) {
            $this->assertDatabaseHas('freshdesk_outbound_operations', ['operation_id' => $id]);
        }
    }
}
