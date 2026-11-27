<?php

namespace Tests\Feature;

use App\Exceptions\FreshdeskApiRateLimitExceededException;
use App\Exceptions\FreshdeskPermanentApiException;
use App\Jobs\ExecuteFreshdeskOutboundOperationJob;
use App\Models\FreshdeskOutboundOperation;
use App\Models\Ticket;
use App\Services\FreshdeskApiService;
use App\Services\Queue\FreshdeskOutboundService;
use App\Services\Sla\AppTimerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreshdeskOutboundPermanentFailureTest extends TestCase
{
    use RefreshDatabase;

    private const TICKET_ID = 90388;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freshdesk.domain' => 'freshdesk.test',
            'freshdesk.api_key' => 'test-key',
        ]);
        Ticket::create([
            'ticket_id' => self::TICKET_ID,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-11-20T08:00:00Z',
        ]);
    }

    public function test_permanent_sync_error_marks_operation_failed_without_requeue(): void
    {
        $operation = $this->dispatchedOperation('sync_sla');
        $sync = $this->mock(AppTimerSyncService::class);
        $sync->shouldReceive('syncTicket')->once()
            ->andThrow(new FreshdeskPermanentApiException('Freshdesk sync failed (status=405)', 405));

        $this->runJob($operation, $sync);

        $fresh = $operation->fresh();
        $this->assertSame('failed', $fresh->state);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->lease_token);
        $this->assertStringContainsString('405', (string) $fresh->last_error);
    }

    public function test_sync_service_reports_405_as_permanent(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Method not allowed'], 405)]);

        $this->expectException(FreshdeskPermanentApiException::class);

        app(AppTimerSyncService::class)->syncTicket(Ticket::query()->findOrFail(self::TICKET_ID));
    }

    public function test_sync_service_reports_503_as_transient(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unavailable'], 503)]);

        try {
            app(AppTimerSyncService::class)->syncTicket(Ticket::query()->findOrFail(self::TICKET_ID));
            $this->fail('Expected the sync to fail.');
        } catch (FreshdeskPermanentApiException) {
            $this->fail('503 must stay retryable.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('503', $exception->getMessage());
        }
    }

    public function test_transient_error_inside_window_is_requeued_with_backoff(): void
    {
        $operation = $this->dispatchedOperation('sync_sla');
        $sync = $this->mock(AppTimerSyncService::class);
        $sync->shouldReceive('syncTicket')->once()
            ->andThrow(new \RuntimeException('Freshdesk sync failed (status=503)'));

        $this->runJob($operation, $sync);

        $fresh = $operation->fresh();
        $this->assertSame('ready', $fresh->state);
        $this->assertTrue($fresh->available_at->isFuture());
        $this->assertNull($fresh->completed_at);
    }

    public function test_transient_error_after_24_hours_marks_operation_failed(): void
    {
        $operation = $this->dispatchedOperation('sync_sla');
        FreshdeskOutboundOperation::query()
            ->whereKey($operation->operation_id)
            ->update(['created_at' => now()->subHours(25)]);
        $sync = $this->mock(AppTimerSyncService::class);
        $sync->shouldReceive('syncTicket')->once()
            ->andThrow(new \RuntimeException('Freshdesk sync failed (status=503)'));

        $this->runJob($operation, $sync);

        $this->assertSame('failed', $operation->fresh()->state);
    }

    public function test_rate_limit_deferral_is_not_treated_as_failure_even_after_24_hours(): void
    {
        $operation = $this->dispatchedOperation('sync_sla');
        FreshdeskOutboundOperation::query()
            ->whereKey($operation->operation_id)
            ->update(['created_at' => now()->subHours(25)]);
        $sync = $this->mock(AppTimerSyncService::class);
        $sync->shouldReceive('syncTicket')->once()
            ->andThrow(new FreshdeskApiRateLimitExceededException(5, 50, 60, 'update_ticket'));

        $this->runJob($operation, $sync);

        $this->assertSame('ready', $operation->fresh()->state);
    }

    public function test_missing_ticket_marks_operation_failed(): void
    {
        $operation = $this->dispatchedOperation('sync_sla', 99999);
        $sync = $this->mock(AppTimerSyncService::class);
        $sync->shouldNotReceive('syncTicket');

        $this->runJob($operation, $sync);

        $this->assertSame('failed', $operation->fresh()->state);
    }

    public function test_group_put_405_marks_command_failed(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => self::TICKET_ID, 'group_id' => 100]);
            }

            return Http::response([
                'description' => 'Validation failed',
                'errors' => [['field' => 'group_id', 'message' => 'There is no group matching the given group_id', 'code' => 'invalid_value']],
            ], 400);
        });
        $operation = $this->dispatchedOperation('change_group', self::TICKET_ID, [
            'old_group_id' => 100,
            'new_group_id' => 200,
        ]);

        $this->runJob($operation, $this->mock(AppTimerSyncService::class));

        $fresh = $operation->fresh();
        $this->assertSame('failed', $fresh->state);
        $this->assertStringContainsString('status=400', (string) $fresh->last_error);
        $this->assertStringContainsString(
            'Validation failed - group_id: There is no group matching the given group_id',
            (string) $fresh->last_error
        );
    }

    public function test_sync_failure_message_includes_freshdesk_validation_details(): void
    {
        Http::fake(['*' => Http::response([
            'description' => 'Validation failed',
            'errors' => [['field' => 'cf_timer_history', 'message' => 'Has 300 characters, it can have maximum of 255', 'code' => 'invalid_value']],
        ], 400)]);

        try {
            app(AppTimerSyncService::class)->syncTicket(Ticket::query()->findOrFail(self::TICKET_ID));
            $this->fail('Expected the sync to fail.');
        } catch (FreshdeskPermanentApiException $exception) {
            $this->assertStringContainsString('status=400', $exception->getMessage());
            $this->assertStringContainsString('cf_timer_history: Has 300 characters', $exception->getMessage());
        }
    }

    public function test_new_sla_sync_supersedes_older_waiting_and_failed_copies(): void
    {
        $ready = $this->operation('sync_sla', 'ready');
        $dispatched = $this->operation('sync_sla', 'dispatched');
        $failed = $this->operation('sync_sla', 'failed');
        $processing = $this->operation('sync_sla', 'processing');
        $command = $this->operation('change_due_date', 'ready');

        $new = app(FreshdeskOutboundService::class)->enqueueCommand(
            self::TICKET_ID,
            'sync_sla',
            'sla-test:'.Str::uuid(),
            []
        );

        $this->assertSame('ready', $new->fresh()->state);
        $this->assertSame('superseded', $ready->fresh()->state);
        $this->assertSame('superseded', $dispatched->fresh()->state);
        $this->assertSame('superseded', $failed->fresh()->state);
        $this->assertSame('processing', $processing->fresh()->state);
        $this->assertSame('ready', $command->fresh()->state);
    }

    public function test_superseded_dispatched_job_does_not_call_freshdesk(): void
    {
        $operation = $this->dispatchedOperation('sync_sla');
        app(FreshdeskOutboundService::class)->supersedePendingSlaSyncs(self::TICKET_ID, (string) Str::uuid());
        $sync = $this->mock(AppTimerSyncService::class);
        $sync->shouldNotReceive('syncTicket');

        $this->runJob($operation, $sync);

        $this->assertSame('superseded', $operation->fresh()->state);
    }

    private function dispatchedOperation(
        string $type,
        int $ticketId = self::TICKET_ID,
        array $payload = []
    ): FreshdeskOutboundOperation {
        return $this->operation($type, 'dispatched', $ticketId, $payload, (string) Str::uuid());
    }

    private function operation(
        string $type,
        string $state,
        int $ticketId = self::TICKET_ID,
        array $payload = [],
        ?string $leaseToken = null
    ): FreshdeskOutboundOperation {
        return FreshdeskOutboundOperation::create([
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => "{$type}:test:".Str::uuid(),
            'ticket_id' => $ticketId,
            'operation_type' => $type,
            'coalesce_key' => $type,
            'generation' => 0,
            'sync_epoch' => 0,
            'operation_version' => 1,
            'payload' => $payload,
            'state' => $state,
            'lease_token' => $leaseToken,
            'attempt_count' => 1,
            'available_at' => now(),
        ]);
    }

    private function runJob(FreshdeskOutboundOperation $operation, AppTimerSyncService $sync): void
    {
        (new ExecuteFreshdeskOutboundOperationJob(
            $operation->operation_id,
            (string) $operation->lease_token,
            0,
            0,
            1
        ))->handle($sync, app(FreshdeskApiService::class));
    }
}
