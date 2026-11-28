<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\Sla\AppTimerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppTimerRtStatusPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_without_reply_reports_end_reason_and_close_time(): void
    {
        $ticket = $this->ticket(91001, [
            'status' => 'Closed',
            'resolved_at' => '2026-11-27T08:20:00Z',
            'closed_at' => '2026-11-27T08:25:00Z',
        ]);
        $ticket->getOrCreateFirstResponseMetric()->forceFill([
            'status' => 'ended_closed_no_reply',
            'first_response_at' => null,
        ])->save();

        $rt = $this->payload($ticket)['rt'];

        $this->assertSame('ended_closed_no_reply', $rt['st']);
        $this->assertSame('2026-11-27T08:20:00Z', $rt['ea']);
    }

    public function test_replied_ticket_reports_first_reply_time_even_after_reopen(): void
    {
        $ticket = $this->ticket(91002, ['status' => 'Open']);
        $ticket->getOrCreateFirstResponseMetric()->forceFill([
            'status' => 'ended_replied',
            'first_response_at' => '2026-11-27T08:07:00Z',
        ])->save();

        $rt = $this->payload($ticket)['rt'];

        $this->assertSame('ended_replied', $rt['st']);
        $this->assertSame('2026-11-27T08:07:00Z', $rt['ea']);
    }

    public function test_running_rt_has_no_end_time_and_keeps_existing_keys(): void
    {
        $ticket = $this->ticket(91003, ['status' => 'Open']);
        $ticket->getOrCreateFirstResponseMetric()->forceFill(['status' => 'running'])->save();

        $rt = $this->payload($ticket)['rt'];

        $this->assertSame('running', $rt['st']);
        $this->assertNull($rt['ea']);
        $this->assertArrayHasKey('at', $rt);
        $this->assertArrayHasKey('us', $rt);
        $this->assertArrayHasKey('rs', $rt);
    }

    private function ticket(int $id, array $attributes): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_id' => $id,
            'priority' => 'High',
            'fd_created_at' => '2026-11-27T08:00:00Z',
        ], $attributes));
    }

    private function payload(Ticket $ticket): array
    {
        return app(AppTimerSyncService::class)->generateCompactJson($ticket->fresh());
    }
}
