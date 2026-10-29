<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\Queue\TicketEventOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketEventOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_timestamp_priority_arriving_after_processed_due_date_requires_replay(): void
    {
        $ticket = $this->ticket();
        $order = app(TicketEventOrderService::class);
        $processedDue = $this->event($ticket, TicketEvent::EVENT_DUE_DATE_CHANGED, 'due', TicketEvent::STATUS_PROCESSED);
        $processedDue->update(['source_order_key' => $order->sourceOrderKey($processedDue)]);
        $incomingPriority = $this->event($ticket, TicketEvent::EVENT_PRIORITY_CHANGED, 'priority', TicketEvent::STATUS_PENDING);

        $this->assertTrue($order->hasProcessedSuccessor($incomingPriority));
    }

    public function test_same_timestamp_due_date_after_processed_priority_does_not_require_replay(): void
    {
        $ticket = $this->ticket();
        $order = app(TicketEventOrderService::class);
        $processedPriority = $this->event($ticket, TicketEvent::EVENT_PRIORITY_CHANGED, 'priority', TicketEvent::STATUS_PROCESSED);
        $processedPriority->update(['source_order_key' => $order->sourceOrderKey($processedPriority)]);
        $incomingDue = $this->event($ticket, TicketEvent::EVENT_DUE_DATE_CHANGED, 'due', TicketEvent::STATUS_PENDING);

        $this->assertFalse($order->hasProcessedSuccessor($incomingDue));
    }

    public function test_older_event_requires_replay_regardless_of_event_type(): void
    {
        $ticket = $this->ticket();
        $order = app(TicketEventOrderService::class);
        $processed = $this->event(
            $ticket,
            TicketEvent::EVENT_STATUS_CHANGED,
            'processed',
            TicketEvent::STATUS_PROCESSED,
            '2026-09-26T10:06:18Z'
        );
        $processed->update(['source_order_key' => $order->sourceOrderKey($processed)]);
        $incoming = $this->event(
            $ticket,
            TicketEvent::EVENT_GROUP_CHANGED,
            'incoming',
            TicketEvent::STATUS_PENDING,
            '2026-09-26T09:56:47Z'
        );

        $this->assertTrue($order->hasProcessedSuccessor($incoming));
    }

    private function ticket(): Ticket
    {
        return Ticket::create([
            'ticket_id' => random_int(100000, 900000),
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'VVIP SLA',
            'fd_created_at' => '2026-09-26T09:56:47Z',
        ]);
    }

    private function event(
        Ticket $ticket,
        string $type,
        string $key,
        string $status,
        string $timestamp = '2026-09-26T10:06:18Z'
    ): TicketEvent {
        return TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', $ticket->ticket_id.$key),
            'event_type' => $type,
            'event_data' => ['ticket_data' => []],
            'field_changes' => [],
            'status' => $status,
            'event_timestamp' => $timestamp,
            'received_at' => now(),
            'processed_at' => $status === TicketEvent::STATUS_PROCESSED ? now() : null,
        ]);
    }
}
