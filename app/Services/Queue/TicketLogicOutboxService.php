<?php

namespace App\Services\Queue;

use App\Models\FreshdeskOutboundOperation;
use App\Models\TicketEvent;
use App\Models\TicketLogicOutbox;
use Illuminate\Support\Facades\DB;

class TicketLogicOutboxService
{
    public function requestForEvent(TicketEvent $event, bool $replay = false): TicketLogicOutbox
    {
        $outbox = TicketLogicOutbox::query()
            ->where('ticket_id', $event->ticket_id)
            ->lockForUpdate()
            ->first();

        if (! $outbox) {
            $outbox = TicketLogicOutbox::create([
                'ticket_id' => $event->ticket_id,
                'state' => 'ready',
                'dispatch_kind' => $replay ? 'replay' : 'normal',
                'requested_generation' => 0,
                'acked_generation' => 0,
                'sync_epoch' => 0,
                'available_at' => now()->addSeconds(20),
            ]);
        }

        $generation = $outbox->requested_generation + 1;
        $enteringReplay = $replay
            && $outbox->dispatch_kind !== 'replay'
            && ! str_starts_with($outbox->state, 'replay_')
            && $outbox->state !== 'replaying';
        $replayRequested = $replay || $outbox->dispatch_kind === 'replay';
        $idle = in_array($outbox->state, ['completed', 'blocked'], true);

        if ($enteringReplay) {
            $outbox->sync_epoch++;
            FreshdeskOutboundOperation::query()
                ->where('ticket_id', $event->ticket_id)
                ->where('coalesce_key', 'sla-sync')
                ->whereIn('state', ['ready', 'dispatched', 'processing'])
                ->update([
                    'state' => 'superseded',
                    'visibility_at' => null,
                    'last_error' => 'Superseded by ticket replay.',
                ]);
        }

        $outbox->forceFill([
            'state' => $replayRequested ? 'replay_requested' : ($idle ? 'ready' : $outbox->state),
            'dispatch_kind' => $replayRequested ? 'replay' : 'normal',
            'requested_generation' => $generation,
            'available_at' => $idle ? now()->addSeconds(20) : $outbox->available_at,
            'last_error' => null,
        ])->save();

        $event->forceFill([
            'logic_generation' => $generation,
            'source_order_key' => app(TicketEventOrderService::class)->sourceOrderKey($event),
        ])->save();

        return $outbox;
    }

    public function requestTicket(int $ticketId, bool $replay = false): void
    {
        DB::transaction(function () use ($ticketId, $replay): void {
            $events = TicketEvent::query()
                ->where('ticket_id', $ticketId)
                ->whereNull('logic_generation')
                ->lockForUpdate()
                ->orderBy('event_timestamp')
                ->orderBy('id')
                ->get();

            foreach ($events as $event) {
                $this->requestForEvent($event, $replay);
            }
        });
    }
}
