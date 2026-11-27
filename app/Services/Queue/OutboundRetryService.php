<?php

namespace App\Services\Queue;

use App\Models\FreshdeskOutboundOperation;
use App\Models\Ticket;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Manual recovery for outbound operations that ended in 'failed'.
 * Shared by the admin page and the outbound:retry-failed command.
 */
class OutboundRetryService
{
    /** Commands an operator may re-run one by one. */
    public const RETRYABLE_COMMANDS = ['change_due_date', 'change_group'];

    public function __construct(private readonly FreshdeskOutboundService $outbound) {}

    /**
     * Queue a fresh sync_sla for the ticket. It pushes the latest DB state, so a
     * single new operation replaces every failed or waiting sync of that ticket.
     */
    public function resyncTicket(int $ticketId): FreshdeskOutboundOperation
    {
        if (! Ticket::query()->where('ticket_id', $ticketId)->exists()) {
            throw new InvalidArgumentException("Ticket {$ticketId} không có trong hệ thống.");
        }

        return $this->outbound->enqueueCommand(
            $ticketId,
            FreshdeskOutboundService::SYNC_SLA,
            "sla-manual-resync:{$ticketId}:".Str::uuid(),
            ['source' => 'manual-retry']
        );
    }

    /**
     * Re-run one failed Due Date / Group command as a new copy. The failed row
     * stays as history. Refused when a newer command of the same type exists,
     * so an old value never overwrites a later change.
     */
    public function retryCommand(string $operationId): FreshdeskOutboundOperation
    {
        $operation = FreshdeskOutboundOperation::query()->find($operationId);
        if (! $operation) {
            throw new InvalidArgumentException('Không tìm thấy thao tác.');
        }

        if ($operation->state !== 'failed'
            || ! in_array($operation->operation_type, self::RETRYABLE_COMMANDS, true)) {
            throw new InvalidArgumentException('Chỉ chạy lại được lệnh đổi Due Date / Group đang failed.');
        }

        $hasNewer = FreshdeskOutboundOperation::query()
            ->where('ticket_id', $operation->ticket_id)
            ->where('operation_type', $operation->operation_type)
            ->where('operation_id', '!=', $operation->operation_id)
            ->where('created_at', '>', $operation->created_at)
            ->whereIn('state', ['ready', 'dispatched', 'processing', 'completed'])
            ->exists();
        if ($hasNewer) {
            throw new InvalidArgumentException('Đã có lệnh mới hơn cùng loại, không chạy lại lệnh cũ.');
        }

        return FreshdeskOutboundOperation::query()->create([
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => "retry:{$operation->operation_id}:".Str::uuid(),
            'ticket_id' => $operation->ticket_id,
            'operation_type' => $operation->operation_type,
            'coalesce_key' => $operation->coalesce_key,
            'generation' => $operation->generation,
            'sync_epoch' => $operation->sync_epoch,
            'operation_version' => 1,
            'payload' => $operation->payload,
            'state' => 'ready',
            'available_at' => now(),
        ]);
    }
}
