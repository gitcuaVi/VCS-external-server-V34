<?php

namespace Tests\Feature;

use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use App\Services\Sla\AppTimerSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AppTimerSyncDurationFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_freshdesk_fail_time_is_formatted_in_configured_timezone(): void
    {
        config(['freshdesk.timezone' => 'Asia/Ho_Chi_Minh']);

        $method = new ReflectionMethod(AppTimerSyncService::class, 'formatFreshdeskDateTime');
        $formatted = $method->invoke(
            app(AppTimerSyncService::class),
            CarbonImmutable::parse('2026-09-21T04:02:13Z')
        );

        $this->assertSame('2026-09-21T11:02:13+07:00', $formatted);
    }

    public function test_freshdesk_duration_fields_are_second_strings(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 91001,
            'status' => 'Closed',
            'priority' => 'High',
            'fd_created_at' => '2026-08-29T00:00:00Z',
            'closed_at' => '2026-08-29T01:00:00Z',
        ]);

        $ticket->getOrCreateFirstResponseMetric()->update([
            'total_seconds' => 43_920,
            'used_seconds' => 0,
            'status' => 'ended_replied',
            'first_response_at' => '2026-08-29T00:01:00Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'total_seconds' => 43_920,
            'used_seconds' => 47_520,
        ]);
        $ticket->getOrCreateGroupMetric('L1')->update([
            'total_seconds' => 43_920,
            'used_seconds' => 3_661,
        ]);

        $fields = $this->buildSlaCustomFields($ticket->fresh());

        $this->assertSame('43920', $fields['cf_rt_time']);
        $this->assertSame('-3600', $fields['cf_ttr_time']);
        $this->assertSame('43920', $fields['cf__l1_time_allowed']);
        $this->assertSame('3661', $fields['cf__l1_time_actual']);

        foreach (['l2', 'l3', 'l4'] as $prefix) {
            $this->assertSame('0', $fields["cf__{$prefix}_time_allowed"]);
            $this->assertSame('0', $fields["cf__{$prefix}_time_actual"]);
        }

        foreach ([
            'cf_rt_time',
            'cf_ttr_time',
            'cf__l1_time_allowed',
            'cf__l1_time_actual',
            'cf__l2_time_allowed',
            'cf__l2_time_actual',
            'cf__l3_time_allowed',
            'cf__l3_time_actual',
            'cf__l4_time_allowed',
            'cf__l4_time_actual',
        ] as $field) {
            $this->assertIsString($fields[$field]);
        }
    }

    public function test_release_one_fail_time_excludes_priority_extra_time(): void
    {
        config([
            'freshdesk.timezone' => 'Asia/Ho_Chi_Minh',
            'freshdesk.fail_time_include_priority_extra' => false,
        ]);

        $ticket = $this->createFailedTicket(91002);

        $fields = $this->buildSlaCustomFields($ticket->fresh());

        $this->assertSame(
            '2026-10-30T00:35:36+07:00',
            $fields['cf_fail_time']
        );
    }

    public function test_release_two_fail_time_can_include_priority_extra_time(): void
    {
        config([
            'freshdesk.timezone' => 'Asia/Ho_Chi_Minh',
            'freshdesk.fail_time_include_priority_extra' => true,
        ]);

        $ticket = $this->createFailedTicket(91003);

        $fields = $this->buildSlaCustomFields($ticket->fresh());

        $this->assertSame(
            '2026-10-30T02:35:36+07:00',
            $fields['cf_fail_time']
        );
    }

    private function createFailedTicket(int $ticketId): Ticket
    {
        $policy = SlaPolicy::create([
            'ticket_type' => 'Incident',
            'priority' => 'High',
            'version' => 1,
            'total_seconds' => 7200,
            'l1_seconds' => 1800,
            'l2_seconds' => 1800,
            'l3_seconds' => 1800,
            'l4_seconds' => 1800,
            'rt_seconds' => 3600,
        ]);

        $ticket = Ticket::create([
            'ticket_id' => $ticketId,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-10-29T10:00:00Z',
        ]);

        $openEvent = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => 'stage_open_event_' . $ticketId,
            'event_type' => TicketEvent::EVENT_TICKET_CREATED,
            'event_data' => [],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSED,
            'event_timestamp' => '2026-10-29T10:00:00Z',
            'received_at' => '2026-10-29T10:00:00Z',
            'processed_at' => '2026-10-29T10:00:00Z',
        ]);

        $stage = TicketSlaStage::create([
            'ticket_id' => $ticket->ticket_id,
            'sla_policy_id' => $policy->id,
            'sequence_number' => 1,
            'trigger_type' => 'creation',
            'priority' => 'High',
            'processing_mode' => 'BusinessHours',
            'opened_at' => CarbonImmutable::parse('2026-10-29T10:00:00Z'),
            'opened_by_event_id' => $openEvent->id,
        ]);

        TicketSlaStageMetric::create([
            'ticket_sla_stage_id' => $stage->id,
            'metric_type' => 'ttr',
            'sla_goal_seconds' => 7200,
            'effective_sla_seconds' => 7200,
            'metric_result' => 'fail',
            'overdue_at' => CarbonImmutable::parse('2026-10-30T02:35:36+07:00')->setTimezone('UTC'),
            'extra_time_granted_seconds' => 7200,
        ]);

        return $ticket;
    }

    private function buildSlaCustomFields(Ticket $ticket): array
    {
        $method = new ReflectionMethod(AppTimerSyncService::class, 'buildSlaCustomFields');

        return $method->invoke(app(AppTimerSyncService::class), $ticket);
    }
}
