<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FreshdeskOutboundOperation;
use App\Services\AdminAuditService;
use App\Services\Queue\OutboundRetryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class OutboundOperationController extends Controller
{
    public const STATES = ['failed', 'ready', 'dispatched', 'processing', 'completed', 'superseded'];

    public function __construct(
        private readonly OutboundRetryService $retry,
        private readonly AdminAuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'state' => ['nullable', 'in:'.implode(',', self::STATES)],
            'ticket_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $state = $filters['state'] ?? 'failed';

        $operations = FreshdeskOutboundOperation::query()
            ->where('state', $state)
            ->when($filters['ticket_id'] ?? null, fn ($query, $ticketId) => $query->where('ticket_id', $ticketId))
            ->orderByDesc('updated_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.outbound_operations', [
            'operations' => $operations,
            'state' => $state,
            'ticketId' => $filters['ticket_id'] ?? null,
            'states' => self::STATES,
            'retryableCommands' => OutboundRetryService::RETRYABLE_COMMANDS,
        ]);
    }

    public function resyncTicket(Request $request, int $ticketId): RedirectResponse
    {
        try {
            $operation = $this->retry->resyncTicket($ticketId);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->audit->record(
            $request,
            'outbound.resync_ticket',
            'ticket',
            $ticketId,
            null,
            ['operation_id' => $operation->operation_id]
        );

        return back()->with('success', "Đã xếp lịch sync lại ticket {$ticketId}.");
    }

    public function retryOperation(Request $request, string $operationId): RedirectResponse
    {
        try {
            $operation = $this->retry->retryCommand($operationId);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->audit->record(
            $request,
            'outbound.retry_command',
            'ticket',
            (int) $operation->ticket_id,
            ['operation_id' => $operationId],
            ['operation_id' => $operation->operation_id, 'operation_type' => $operation->operation_type]
        );

        return back()->with('success', "Đã xếp lịch chạy lại {$operation->operation_type} cho ticket {$operation->ticket_id}.");
    }
}
