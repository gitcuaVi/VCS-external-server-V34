<?php

namespace App\Services\Queue;

use App\Models\FreshdeskOutboundOperation;
use App\Models\TicketEvent;
use App\Models\TicketLogicOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreshdeskOutboundService
{
    public const SYNC_SLA = 'sync_sla';

    public function enqueueForEvent(
        TicketEvent $event,
        string $operationType,
        string $coalesceKey,
        array $payload
    ): FreshdeskOutboundOperation {
        $outbox = TicketLogicOutbox::query()
            ->where('ticket_id', $event->ticket_id)
            ->lockForUpdate()
            ->firstOrFail();
        $generation = $event->logic_generation ?? $outbox->requested_generation;
        // Business side effects belong to the immutable source event. Replay may
        // recalculate SLA generations, but it must never recreate the remote action.
        $idempotencyKey = implode(':', [$operationType, $event->id]);

        return FreshdeskOutboundOperation::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'operation_id' => (string) Str::uuid(),
                'ticket_id' => $event->ticket_id,
                'operation_type' => $operationType,
                'coalesce_key' => $coalesceKey,
                'generation' => $generation,
                'sync_epoch' => $outbox->sync_epoch,
                'operation_version' => 1,
                'payload' => $payload,
                'state' => 'ready',
                'available_at' => now(),
            ]
        );
    }

    public function enqueueCommand(
        int $ticketId,
        string $operationType,
        string $idempotencyKey,
        array $payload
    ): FreshdeskOutboundOperation {
        return DB::transaction(function () use (
            $ticketId,
            $operationType,
            $idempotencyKey,
            $payload
        ): FreshdeskOutboundOperation {
            $outbox = TicketLogicOutbox::query()
                ->where('ticket_id', $ticketId)
                ->lockForUpdate()
                ->first();

            $operation = FreshdeskOutboundOperation::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'operation_id' => (string) Str::uuid(),
                    'ticket_id' => $ticketId,
                    'operation_type' => $operationType,
                    'coalesce_key' => $operationType,
                    'generation' => (int) ($outbox?->requested_generation ?? 0),
                    'sync_epoch' => (int) ($outbox?->sync_epoch ?? 0),
                    'operation_version' => 1,
                    'payload' => $payload,
                    'state' => 'ready',
                    'available_at' => now(),
                ]
            );

            if ($operationType === self::SYNC_SLA && $operation->wasRecentlyCreated) {
                $this->supersedePendingSlaSyncs($ticketId, $operation->operation_id);
            }

            return $operation;
        });
    }

    /**
     * A sync_sla always pushes the latest DB state, so only the newest one per
     * ticket is useful. Older waiting or failed copies become superseded.
     *
     * Operations in 'processing' are left alone. A 'dispatched' copy that is
     * superseded here is skipped by the job, whose claim requires
     * state = 'dispatched'.
     */
    public function supersedePendingSlaSyncs(int $ticketId, string $keepOperationId): int
    {
        return FreshdeskOutboundOperation::query()
            ->where('ticket_id', $ticketId)
            ->where('operation_type', self::SYNC_SLA)
            ->whereIn('state', ['ready', 'dispatched', 'failed'])
            ->where('operation_id', '!=', $keepOperationId)
            ->update([
                'state' => 'superseded',
                'lease_token' => null,
                'visibility_at' => null,
                'completed_at' => now(),
                'last_error' => "Superseded by {$keepOperationId}",
            ]);
    }
}
