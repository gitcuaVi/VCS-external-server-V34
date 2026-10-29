<?php

namespace Tests\Feature;

use App\Jobs\ExecuteFreshdeskOutboundOperationJob;
use App\Models\FreshdeskOutboundOperation;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketDueDateChange;
use App\Models\TicketEvent;
use App\Models\TicketHistory;
use App\Models\TicketSlaStage;
use App\Models\TicketSlaStageMetric;
use App\Services\FreshdeskApiService;
use App\Services\Sla\AppTimerSyncService;
use App\Services\Sla\DueDateChangedHandler;
use App\Services\Sla\PriorityChangedHandler;
use App\Services\Sla\RequesterRepliedHandler;
use App\Services\Sla\SlaInitializationService;
use App\Services\Sla\SlaStageService;
use App\Services\Sla\TimelineService;
use App\Services\Sla\TimerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ReleaseOneSafetyFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freshdesk.basic_auth.username' => 'qa-user',
            'freshdesk.basic_auth.password' => 'qa-password',
        ]);
    }

    public function test_change_due_date_is_accepted_once_without_calling_freshdesk_in_http_request(): void
    {
        Http::fake();
        Ticket::create([
            'ticket_id' => 18321,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'VVIP SLA',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $payload = [
            'ticket_id' => 18321,
            'new_due_date' => '2026-08-05T08:00:00Z',
            'processing_phase' => 'L4',
            'reason' => 'QA adjustment',
            'agent_name' => 'QA',
        ];

        $first = $this->withBasicAuth('qa-user', 'qa-password')
            ->withHeader('Idempotency-Key', 'qa-change-18321-1')
            ->postJson('/api/tickets/change-due-date', $payload);
        $second = $this->withBasicAuth('qa-user', 'qa-password')
            ->withHeader('Idempotency-Key', 'qa-change-18321-1')
            ->postJson('/api/tickets/change-due-date', $payload);

        $first->assertStatus(202)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false);
        $second->assertStatus(202)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('operation_id', $first->json('operation_id'));
        $this->assertSame(1, FreshdeskOutboundOperation::query()->count());
        $this->assertSame(
            'change_due_date',
            FreshdeskOutboundOperation::query()->value('operation_type')
        );
        Http::assertNothingSent();
    }

    public function test_history_deduplication_keeps_identical_replies_from_different_events(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18322,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $first = $this->event($ticket, 'first', '2026-07-30T08:01:00Z');
        $second = $this->event($ticket, 'second', '2026-07-30T08:02:00Z');
        $timeline = app(TimelineService::class);

        $timeline->appendTicketEventLog($ticket, 'a', 'rep', '2026-07-30T08:01:00Z', '100', $first);
        $timeline->appendTicketEventLog($ticket, 'a', 'rep', '2026-07-30T08:02:00Z', '100', $second);
        $timeline->appendTicketEventLog($ticket, 'a', 'rep', '2026-07-30T08:02:00Z', '100', $second);

        $this->assertSame(2, TicketHistory::query()->count());
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            TicketHistory::query()->pluck('ticket_event_id')->all()
        );
    }

    public function test_requester_reply_on_closed_ticket_does_not_trigger_release_two_reopen_flow(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18325,
            'status' => 'Closed',
            'priority' => 'High',
            'fd_created_at' => '2026-07-29T08:00:00Z',
            'closed_at' => '2026-07-30T08:00:00Z',
        ]);
        $event = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', 'closed-requester-reply-release-one'),
            'event_type' => TicketEvent::EVENT_REQUESTER_REPLIED,
            'event_data' => [
                'ticket_data' => ['updated_at' => '2026-07-30T09:00:00Z'],
                'conversation_data' => [
                    'actor_id' => 100,
                    'updated_at' => '2026-07-30T09:00:00Z',
                ],
            ],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => '2026-07-30T09:00:00Z',
            'received_at' => '2026-07-30T09:00:00Z',
        ]);

        app(RequesterRepliedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event
        );

        $this->assertDatabaseCount('freshdesk_outbound_operations', 0);
        $this->assertNull($ticket->fresh()->reopened_at);
        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $ticket->ticket_id,
            'ticket_event_id' => $event->id,
            'event_key' => 'ct',
            'event_value' => 'rep',
        ]);
    }

    public function test_change_due_date_outbound_operation_updates_freshdesk_once(): void
    {
        config([
            'freshdesk.domain' => 'freshdesk.test',
            'freshdesk.api_key' => 'test-key',
        ]);
        Ticket::create([
            'ticket_id' => 18324,
            'status' => 'Open',
            'priority' => 'High',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $operationId = (string) Str::uuid();
        $leaseToken = (string) Str::uuid();
        FreshdeskOutboundOperation::create([
            'operation_id' => $operationId,
            'idempotency_key' => 'change-due-date:test',
            'ticket_id' => 18324,
            'operation_type' => 'change_due_date',
            'coalesce_key' => 'change_due_date',
            'generation' => 0,
            'sync_epoch' => 0,
            'operation_version' => 1,
            'payload' => [
                'new_due_date' => '2026-08-05T08:00:00+00:00',
                'processing_phase' => 'L4',
                'reason' => 'QA adjustment',
                'agent_name' => 'QA',
            ],
            'state' => 'dispatched',
            'lease_token' => $leaseToken,
            'available_at' => now(),
        ]);
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => 18324,
                    'tags' => [],
                    'custom_fields' => [
                        'cf_number_of_due_date_changes_2885394' => 0,
                        'cf_processing_mode_2885394' => 'priority-driven',
                        'cf_processing_phase_2885394' => null,
                        'cf_change_due_reason_2885394' => null,
                    ],
                ]);
            }

            return Http::response(['success' => true], 200);
        });

        (new ExecuteFreshdeskOutboundOperationJob(
            $operationId,
            $leaseToken,
            0,
            0,
            1
        ))->handle(
            $this->mock(AppTimerSyncService::class),
            app(FreshdeskApiService::class)
        );

        $operation = FreshdeskOutboundOperation::query()->findOrFail($operationId);
        $this->assertSame('completed', $operation->state);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request['due_by'] === '2026-08-05T08:00:00+00:00'
            && $request['custom_fields']['cf_processing_mode_2885394'] === 'due-driven'
            && in_array('due_date_change (1)', $request['tags'], true)
        );
        Http::assertSentCount(3);
    }

    public function test_due_date_webhook_preserves_incoming_due_driven_mode(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18323,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'NO_POLICY',
            'fd_created_at' => '2026-07-30T08:00:00Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => 'priority-driven',
            'latest_due_date_ttr' => '2026-08-05T08:00:00Z',
        ]);
        $this->appDueDateOperation($ticket, '2026-08-04T08:00:00Z', 'mode-transition');
        $event = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', 'due-driven-event'),
            'event_type' => TicketEvent::EVENT_DUE_DATE_CHANGED,
            'event_data' => [
                'ticket_data' => [
                    'due_by' => '2026-08-04T08:00:00Z',
                    'custom_fields' => [
                        'cf_processing_mode_2885394' => 'due-driven',
                    ],
                ],
            ],
            'field_changes' => [[
                'field' => 'due_by',
                'old_value' => '2026-08-05T08:00:00Z',
                'new_value' => '2026-08-04T08:00:00Z',
            ]],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => '2026-08-01T08:00:00Z',
            'received_at' => now(),
        ]);

        $initialization = Mockery::mock(SlaInitializationService::class);
        $initialization->shouldReceive('ensureSlaInitialized')->once();
        $timeline = Mockery::mock(TimelineService::class);
        $timeline->shouldReceive('appendTicketEventLog')->once();
        $timerService = Mockery::mock(TimerService::class)->shouldIgnoreMissing();
        $timerService->shouldReceive('calculateTtrUsedSeconds')->once()->andReturn(0);
        $handler = new DueDateChangedHandler(
            $timerService,
            $initialization,
            $timeline,
            Mockery::mock(SlaStageService::class)
        );

        $handler->handle($ticket->ticket_id, $event->getTicketData(), $event->getFieldChanges(), $event);

        $this->assertSame('due-driven', $ticket->getOrCreateTtrMetric()->fresh()->processing_mode);
    }

    public function test_non_app_due_date_change_keeps_priority_driven_mode(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18326,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'NO_POLICY',
            'fd_created_at' => '2026-09-21T02:08:49Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => 'priority-driven',
            'total_seconds' => 1209600,
            'latest_due_date_ttr' => '2026-10-05T02:08:49Z',
        ]);
        $event = $this->dueDateEvent(
            $ticket,
            'priority-derived-due-date',
            '2026-10-05T02:08:49Z',
            '2026-09-22T02:08:50Z'
        );

        app(DueDateChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $metric = $ticket->getOrCreateTtrMetric()->fresh();
        $this->assertSame('priority-driven', $metric->processing_mode);
        $this->assertSame('2026-09-22T02:08:50+00:00', $metric->latest_due_date_ttr->toIso8601String());
        $this->assertNull($metric->mode_switched_at);
        $this->assertDatabaseCount('ticket_sla_stages', 0);
        $this->assertDatabaseCount('ticket_due_date_changed', 0);
    }

    public function test_priority_generated_due_date_reconciles_open_priority_stage_without_creating_another_stage(): void
    {
        $initialPolicy = SlaPolicy::create([
            'ticket_type' => 'VIP',
            'priority' => 'Urgent',
            'version' => 1,
            'total_seconds' => 21600,
            'l1_seconds' => 3600,
            'l2_seconds' => 3600,
            'l3_seconds' => 7200,
            'l4_seconds' => 7200,
            'rt_seconds' => 7200,
        ]);
        $vipHighPolicy = SlaPolicy::create([
            'ticket_type' => 'VIP',
            'priority' => 'High',
            'version' => 1,
            'total_seconds' => 86400,
            'l1_seconds' => 7200,
            'l2_seconds' => 14400,
            'l3_seconds' => 21600,
            'l4_seconds' => 43200,
            'rt_seconds' => 28800,
        ]);
        $vvipHighPolicy = SlaPolicy::create([
            'ticket_type' => 'VVIP SLA',
            'priority' => 'High',
            'version' => 1,
            'total_seconds' => 28800,
            'l1_seconds' => 3600,
            'l2_seconds' => 3600,
            'l3_seconds' => 7200,
            'l4_seconds' => 14400,
            'rt_seconds' => 14400,
        ]);

        $ticket = Ticket::create([
            'ticket_id' => 19594,
            'status' => 'Open',
            'priority' => 'Urgent',
            'ticket_type' => 'VIP',
            'fd_created_at' => '2026-09-26T09:56:47Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'total_seconds' => $initialPolicy->total_seconds,
            'used_seconds' => 0,
            'processing_mode' => 'priority-driven',
            'original_due_date_ttr' => '2026-09-26T15:56:47Z',
            'latest_due_date_ttr' => '2026-09-26T15:56:47Z',
        ]);
        $ticket->getOrCreateFirstResponseMetric()->update([
            'total_seconds' => $initialPolicy->rt_seconds,
            'used_seconds' => 0,
            'status' => 'running',
            'started_at' => '2026-09-26T09:56:47Z',
            'original_due_date_rt' => '2026-09-26T11:56:47Z',
            'latest_due_date_rt' => '2026-09-26T11:56:47Z',
        ]);

        $createdEvent = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', '19594-created'),
            'event_type' => TicketEvent::EVENT_TICKET_CREATED,
            'event_data' => ['ticket_data' => ['priority' => 'Urgent', 'ticket_type' => 'VIP']],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSED,
            'event_timestamp' => '2026-09-26T09:56:47Z',
            'received_at' => now(),
        ]);
        $initialStage = TicketSlaStage::create([
            'ticket_id' => $ticket->ticket_id,
            'sla_policy_id' => $initialPolicy->id,
            'sequence_number' => 1,
            'priority_stage_number' => 1,
            'trigger_type' => 'initial',
            'priority' => 'Urgent',
            'processing_mode' => 'priority-driven',
            'opened_at' => '2026-09-26T09:56:47Z',
            'opened_by_event_id' => $createdEvent->id,
        ]);
        foreach ([
            ['type' => 'ttr', 'goal' => 21600, 'due' => '2026-09-26T15:56:47Z'],
            ['type' => 'rt', 'goal' => 7200, 'due' => '2026-09-26T11:56:47Z'],
        ] as $metric) {
            TicketSlaStageMetric::create([
                'ticket_sla_stage_id' => $initialStage->id,
                'metric_type' => $metric['type'],
                'sla_goal_seconds' => $metric['goal'],
                'used_before_seconds' => 0,
                'effective_sla_seconds' => $metric['goal'],
                'standard_due_at' => $metric['due'],
                'adjusted_due_at' => $metric['due'],
            ]);
        }

        $priorityEvent = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', '19594-priority-high'),
            'event_type' => TicketEvent::EVENT_PRIORITY_CHANGED,
            'event_data' => ['ticket_data' => ['priority' => 'High']],
            'field_changes' => [[
                'field' => 'priority',
                'old_value' => 'Urgent',
                'new_value' => 'High',
            ]],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => '2026-09-26T09:56:47Z',
            'received_at' => now(),
        ]);
        app(PriorityChangedHandler::class)->handle(
            $ticket->ticket_id,
            $priorityEvent->getTicketData(),
            $priorityEvent->getFieldChanges(),
            $priorityEvent
        );

        $this->assertSame($vipHighPolicy->id, TicketSlaStage::query()->latest('sequence_number')->value('sla_policy_id'));

        $dueEvent = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', '19594-system-due-high'),
            'event_type' => TicketEvent::EVENT_DUE_DATE_CHANGED,
            'event_data' => ['ticket_data' => [
                'priority' => null,
                'ticket_type' => 'VVIP SLA',
                'due_by' => '2026-09-26T17:56:47Z',
                'fr_due_by' => '2026-09-26T13:56:47Z',
                'custom_fields' => [],
            ]],
            'field_changes' => [
                ['field' => 'ticket_type', 'old_value' => 'VIP', 'new_value' => 'VVIP SLA'],
                ['field' => 'due_by', 'old_value' => '2026-09-26T15:56:47Z', 'new_value' => '2026-09-26T17:56:47Z'],
            ],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => '2026-09-26T09:56:47Z',
            'received_at' => now(),
        ]);
        app(DueDateChangedHandler::class)->handle(
            $ticket->ticket_id,
            $dueEvent->getTicketData(),
            $dueEvent->getFieldChanges(),
            $dueEvent
        );

        $stages = TicketSlaStage::query()->where('ticket_id', $ticket->ticket_id)->orderBy('sequence_number')->get();
        $activeStage = $stages->last()->load('metrics');

        $this->assertCount(2, $stages);
        $this->assertSame('priority_change', $activeStage->trigger_type);
        $this->assertSame('High', $activeStage->priority);
        $this->assertSame($vvipHighPolicy->id, $activeStage->sla_policy_id);
        $this->assertSame(28800, $activeStage->metrics->firstWhere('metric_type', 'ttr')->sla_goal_seconds);
        $this->assertSame(14400, $activeStage->metrics->firstWhere('metric_type', 'rt')->sla_goal_seconds);
        $this->assertDatabaseCount('ticket_due_date_changed', 0);
        $this->assertSame('priority-driven', $ticket->getOrCreateTtrMetric()->fresh()->processing_mode);
    }

    public function test_due_driven_marker_without_matching_app_operation_does_not_switch_mode(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18327,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'NO_POLICY',
            'fd_created_at' => '2026-09-21T02:08:49Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => 'priority-driven',
            'latest_due_date_ttr' => '2026-09-22T02:08:49Z',
        ]);
        $event = $this->dueDateEvent(
            $ticket,
            'untrusted-due-driven-marker',
            '2026-09-22T02:08:49Z',
            '2026-09-23T02:08:49Z',
            'due-driven'
        );

        app(DueDateChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $this->assertSame('priority-driven', $ticket->getOrCreateTtrMetric()->fresh()->processing_mode);
    }

    public function test_noop_app_due_date_change_does_not_switch_mode_or_recalculate(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18328,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'NO_POLICY',
            'fd_created_at' => '2026-09-21T02:08:49Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => 'priority-driven',
            'total_seconds' => 86400,
            'latest_due_date_ttr' => '2026-09-22T02:08:49Z',
        ]);
        $this->appDueDateOperation($ticket, '2026-09-22T02:08:49Z', 'noop');
        $event = $this->dueDateEvent(
            $ticket,
            'noop-app-due-date',
            '2026-09-22T02:08:49Z',
            '2026-09-22T02:08:49Z',
            'due-driven'
        );

        app(DueDateChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $metric = $ticket->getOrCreateTtrMetric()->fresh();
        $this->assertSame('priority-driven', $metric->processing_mode);
        $this->assertSame(86400, $metric->total_seconds);
        $this->assertDatabaseCount('ticket_sla_stages', 0);
    }

    public function test_due_driven_mode_is_terminal(): void
    {
        $ticket = Ticket::create([
            'ticket_id' => 18329,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'NO_POLICY',
            'fd_created_at' => '2026-09-21T02:08:49Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => 'due-driven',
            'latest_due_date_ttr' => '2026-09-22T02:08:49Z',
            'mode_switched_at' => '2026-09-21T03:00:00Z',
            'used_seconds_at_mode_switch' => 3000,
        ]);
        $event = $this->dueDateEvent(
            $ticket,
            'attempted-mode-downgrade',
            '2026-09-22T02:08:49Z',
            '2026-09-23T02:08:49Z',
            'priority-driven'
        );

        app(DueDateChangedHandler::class)->handle(
            $ticket->ticket_id,
            $event->getTicketData(),
            $event->getFieldChanges(),
            $event
        );

        $metric = $ticket->getOrCreateTtrMetric()->fresh();
        $this->assertSame('due-driven', $metric->processing_mode);
        $this->assertNotNull($metric->mode_switched_at);
        $this->assertSame(3000, $metric->used_seconds_at_mode_switch);
    }

    public function test_due_date_stage_records_custom_fields_from_event_payload(): void
    {
        SlaPolicy::create([
            'ticket_type' => 'VVIP',
            'priority' => 'High',
            'version' => 1,
            'total_seconds' => 28800,
            'l1_seconds' => 3600,
            'l2_seconds' => 3600,
            'l3_seconds' => 7200,
            'l4_seconds' => 14400,
            'rt_seconds' => 14400,
        ]);

        $ticket = Ticket::create([
            'ticket_id' => 18487,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'VVIP',
            'fd_created_at' => '2026-08-02T02:47:22Z',
        ]);
        $ticket->getOrCreateTtrMetric()->update([
            'processing_mode' => 'priority-driven',
            'latest_due_date_ttr' => '2026-08-02T10:47:22Z',
            'total_seconds' => 28800,
        ]);
        $this->appDueDateOperation($ticket, '2026-08-08T16:59:00Z', 'stage-custom-fields');

        $event = TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', 'due-date-stage-custom-fields'),
            'event_type' => TicketEvent::EVENT_DUE_DATE_CHANGED,
            'event_data' => [
                'ticket_data' => [
                    'due_by' => '2026-08-08T16:59:00Z',
                    'custom_fields' => [
                        'cf_processing_mode' => 'due-driven',
                        'cf_processing_phase' => 'Investigation',
                        'cf_change_due_reason' => 'Phase changed',
                    ],
                ],
            ],
            'field_changes' => [[
                'field' => 'due_by',
                'old_value' => '2026-08-02T10:47:22Z',
                'new_value' => '2026-08-08T16:59:00Z',
            ]],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => '2026-08-03T00:52:12Z',
            'received_at' => now(),
        ]);

        $initialization = Mockery::mock(SlaInitializationService::class);
        $initialization->shouldReceive('ensureSlaInitialized')->once();
        $timeline = Mockery::mock(TimelineService::class);
        $timeline->shouldReceive('appendTicketEventLog')->once();
        $stageService = Mockery::mock(SlaStageService::class);
        $stageService->shouldReceive('checkpointOpenStage')->once();
        $timerService = Mockery::mock(TimerService::class)->shouldIgnoreMissing();

        $handler = new DueDateChangedHandler(
            $timerService,
            $initialization,
            $timeline,
            $stageService
        );

        $handler->handle($ticket->ticket_id, $event->getTicketData(), $event->getFieldChanges(), $event);

        $change = TicketDueDateChange::query()->where('ticket_id', $ticket->ticket_id)->firstOrFail();
        $this->assertSame('Investigation', $change->processing_phase);
        $this->assertSame('Phase changed', $change->reason_code);
    }

    private function appDueDateOperation(Ticket $ticket, string $newDueDate, string $key): FreshdeskOutboundOperation
    {
        return FreshdeskOutboundOperation::create([
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => "change-due-date:{$key}",
            'ticket_id' => $ticket->ticket_id,
            'operation_type' => 'change_due_date',
            'coalesce_key' => 'change_due_date',
            'generation' => 0,
            'sync_epoch' => 0,
            'operation_version' => 1,
            'payload' => ['new_due_date' => $newDueDate],
            'state' => 'completed',
            'available_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function dueDateEvent(
        Ticket $ticket,
        string $key,
        string $oldDue,
        string $newDue,
        ?string $processingMode = null
    ): TicketEvent {
        $customFields = $processingMode === null
            ? []
            : ['cf_processing_mode' => $processingMode];

        return TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', $key),
            'event_type' => TicketEvent::EVENT_DUE_DATE_CHANGED,
            'event_data' => ['ticket_data' => [
                'due_by' => $newDue,
                'custom_fields' => $customFields,
            ]],
            'field_changes' => [[
                'field' => 'due_by',
                'old_value' => $oldDue,
                'new_value' => $newDue,
            ]],
            'status' => TicketEvent::STATUS_PROCESSING,
            'event_timestamp' => '2026-09-21T03:00:00Z',
            'received_at' => now(),
        ]);
    }

    private function event(Ticket $ticket, string $key, string $occurredAt): TicketEvent
    {
        return TicketEvent::create([
            'ticket_id' => $ticket->ticket_id,
            'idempotency_key' => hash('sha256', $key),
            'event_type' => TicketEvent::EVENT_AGENT_REPLIED,
            'event_data' => ['ticket_data' => []],
            'field_changes' => [],
            'status' => TicketEvent::STATUS_PROCESSED,
            'event_timestamp' => $occurredAt,
            'received_at' => $occurredAt,
            'processed_at' => $occurredAt,
        ]);
    }
}
