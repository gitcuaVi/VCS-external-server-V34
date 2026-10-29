<?php

namespace App\Services\Queue;

use App\Models\TicketEvent;

/**
 * Canonical ordering for all events of one ticket.
 *
 * Freshdesk commonly emits several webhooks with the same second-level
 * timestamp. The deterministic source key prevents arrival order from changing
 * SLA results and lets ingress detect a same-timestamp event that needs replay.
 */
class TicketEventOrderService
{
    public function sourceOrderKey(TicketEvent $event): string
    {
        $actor = data_get($event->event_data, 'conversation_data.actor_id', 'none');

        return implode('|', [
            $event->event_timestamp->utc()->format('Y-m-d\TH:i:s.u\Z'),
            str_pad((string) array_search($event->event_type, TicketEvent::SUPPORTED_EVENT_TYPES, true), 3, '0', STR_PAD_LEFT),
            hash('sha256', (string) $actor.'|'.$event->idempotency_key),
        ]);
    }

    public function hasProcessedSuccessor(TicketEvent $event): bool
    {
        $sourceOrderKey = $event->source_order_key ?: $this->sourceOrderKey($event);

        return TicketEvent::query()
            ->where('ticket_id', $event->ticket_id)
            ->where('id', '!=', $event->id)
            ->where('status', TicketEvent::STATUS_PROCESSED)
            ->where(function ($query) use ($event, $sourceOrderKey): void {
                $query->where('event_timestamp', '>', $event->event_timestamp)
                    ->orWhere(function ($sameTimestamp) use ($event, $sourceOrderKey): void {
                        $sameTimestamp->where('event_timestamp', $event->event_timestamp)
                            ->where(function ($laterOrder) use ($event, $sourceOrderKey): void {
                                $laterOrder->where('source_order_key', '>', $sourceOrderKey)
                                    ->orWhere(function ($sameOrder) use ($event, $sourceOrderKey): void {
                                        $sameOrder->where('source_order_key', $sourceOrderKey)
                                            ->where('id', '>', $event->id);
                                    });
                            });
                    });
            })
            ->exists();
    }
}
