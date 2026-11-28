<?php

namespace Tests\Feature;

use App\Http\Controllers\WebhookController;
use App\Jobs\PersistFreshdeskWebhookJob;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Services\FreshdeskGroupSyncService;
use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoLiveTrackedTicketFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
        $this->travelTo('2026-11-27T03:00:00Z');
        config([
            'freshdesk_spool.enabled' => false,
            'freshdesk.basic_auth.username' => 'qa-user',
            'freshdesk.basic_auth.password' => 'qa-password',
            'freshdesk.enable_legacy_ticket_filter' => true,
            'freshdesk.go_live_timestamp' => '2026-11-20T00:00:00+07:00',
            'freshdesk.untracked_event_grace_hours' => 2,
        ]);
    }

    public function test_recent_reply_for_unknown_ticket_waits_for_ticket_created(): void
    {
        $this->reply(92001, now()->subMinutes(5)->toIso8601ZuluString())
            ->assertStatus(503)
            ->assertJsonPath('reason', 'ticket_not_tracked_yet');

        $this->assertDatabaseMissing('tickets', ['ticket_id' => 92001]);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_old_reply_for_legacy_ticket_is_ignored_without_creating_ticket(): void
    {
        $this->reply(92002, now()->subHours(3)->toIso8601ZuluString())
            ->assertOk()
            ->assertJsonPath('ignored', true)
            ->assertJsonPath('reason', 'untracked_ticket');

        $this->assertDatabaseMissing('tickets', ['ticket_id' => 92002]);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_ticket_created_before_go_live_is_still_ignored(): void
    {
        $this->created(92003, '2026-11-10T08:00:00Z')
            ->assertOk()
            ->assertJsonPath('reason', 'legacy_ticket_phase_1');

        $this->assertDatabaseMissing('tickets', ['ticket_id' => 92003]);
    }

    public function test_reply_retried_after_ticket_created_is_processed_with_db_fields(): void
    {
        $replyAt = now()->subMinutes(2)->toIso8601ZuluString();
        $this->reply(92004, $replyAt)->assertStatus(503);

        $this->created(92004, now()->subMinutes(3)->toIso8601ZuluString())->assertOk();
        $this->reply(92004, $replyAt)->assertOk()->assertJsonMissingPath('ignored');

        $event = TicketEvent::query()
            ->where('ticket_id', 92004)
            ->where('event_type', TicketEvent::EVENT_AGENT_REPLIED)
            ->sole();
        $ticketData = $event->event_data['ticket_data'];
        $this->assertSame('Open', $ticketData['status']);
        $this->assertSame('High', $ticketData['priority']);
    }

    public function test_without_go_live_config_unknown_ticket_keeps_previous_behaviour(): void
    {
        config(['freshdesk.go_live_timestamp' => null]);

        // The untracked-ticket rule only applies when the go-live filter is on.
        $response = $this->reply(92005, now()->subMinutes(5)->toIso8601ZuluString());

        $this->assertNotSame('ticket_not_tracked_yet', $response->json('reason'));
        $this->assertNotSame('untracked_ticket', $response->json('reason'));
    }

    public function test_batch_rejects_ticket_created_before_go_live(): void
    {
        $response = $this->withBasicAuth('qa-user', 'qa-password')
            ->postJson('/api/webhooks/batch', ['events' => [[
                'recovery_id' => 'rec-92006',
                'ticket_id' => 92006,
                'event_type' => 'ticket_created',
                'event_timestamp' => '2026-11-10T08:00:00Z',
                'ticket_data' => $this->ticketData(92006, '2026-11-10T08:00:00Z'),
            ]]]);

        $response->assertStatus(202)->assertJsonPath('data.results.0.reason', 'legacy_ticket');
        $this->assertDatabaseMissing('tickets', ['ticket_id' => 92006]);
    }

    public function test_batch_accepts_ticket_created_after_go_live(): void
    {
        $this->withBasicAuth('qa-user', 'qa-password')
            ->postJson('/api/webhooks/batch', ['events' => [[
                'recovery_id' => 'rec-92007',
                'ticket_id' => 92007,
                'event_type' => 'ticket_created',
                'event_timestamp' => '2026-11-26T08:00:00Z',
                'ticket_data' => $this->ticketData(92007, '2026-11-26T08:00:00Z'),
            ]]])
            ->assertStatus(202);

        $this->assertTrue(Ticket::query()->where('ticket_id', 92007)->exists());
    }

    public function test_spool_retries_early_reply_and_processes_it_after_ticket_created(): void
    {
        // The spool ages files with the real wall clock (time()), so a frozen
        // test date would make the file look older than the 24h retry window.
        $this->travelBack();
        Log::spy();

        $root =sys_get_temp_dir().DIRECTORY_SEPARATOR.'v34-golive-spool-'.bin2hex(random_bytes(6));
        config([
            'freshdesk_spool.root' => $root,
            'freshdesk_spool.require_directory_fsync' => false,
        ]);
        $spool = app(DurableWebhookSpool::class);
        $replyAt = now()->subMinute()->toIso8601ZuluString();

        try {
            $spool->accept([
                'ticket_id' => 92010,
                'event_type' => 'agent_replied',
                'event_timestamp' => $replyAt,
                'conversation_data' => ['actor_id' => 501, 'created_at' => $replyAt, 'updated_at' => $replyAt],
            ], 'golive-reply');
            $this->runSpool($spool);

            // Waiting for ticket_created: back in ready with backoff, not quarantined.
            $this->assertSame([], $spool->findFiles('quarantine', 10));
            $this->assertCount(1, $spool->findFiles('ready', 10));
            $this->assertDatabaseCount('ticket_events', 0);

            $spool->accept([
                'ticket_id' => 92010,
                'event_type' => 'ticket_created',
                'event_timestamp' => now()->subMinutes(2)->toIso8601ZuluString(),
                'ticket_data' => $this->ticketData(92010, now()->subMinutes(2)->toIso8601ZuluString()),
            ], 'golive-created');
            $this->runSpool($spool);
            // The spool schedules retries on wall-clock time (first backoff: 5s).
            sleep(6);
            $this->runSpool($spool);

            $this->assertSame([], $spool->findFiles('ready', 10));
            $this->assertSame([], $spool->findFiles('quarantine', 10));
            $this->assertSame(
                [TicketEvent::EVENT_TICKET_CREATED, TicketEvent::EVENT_AGENT_REPLIED],
                TicketEvent::query()->where('ticket_id', 92010)->orderBy('event_timestamp')->pluck('event_type')->all()
            );

            // Every inbound step is traceable by ticket ID in the log file.
            Log::shouldHaveReceived('info')->withArgs(
                fn (string $message, array $context = []): bool => $message === 'Freshdesk webhook waiting for ticket_created'
                    && ($context['ticket_id'] ?? null) === 92010
                    && ($context['event_type'] ?? null) === 'agent_replied'
            )->once();
            Log::shouldHaveReceived('info')->withArgs(
                fn (string $message, array $context = []): bool => $message === 'Freshdesk inbound webhook processed'
                    && ($context['ticket_id'] ?? null) === 92010
                    && ($context['event_type'] ?? null) === 'agent_replied'
                    && ($context['result'] ?? null) === 'accepted'
                    && ($context['attempt'] ?? null) === 2
            )->once();
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function runSpool(DurableWebhookSpool $spool): void
    {
        foreach ($spool->findDueReadyFiles(10) as $ready) {
            $dispatch = $spool->claimForDispatch($ready);
            (new PersistFreshdeskWebhookJob($dispatch['destination'], $dispatch['token']))
                ->handle($spool, app(WebhookController::class), app(FreshdeskGroupSyncService::class));
        }
    }

    private function reply(int $ticketId, string $at)
    {
        return $this->withBasicAuth('qa-user', 'qa-password')
            ->postJson('/api/webhooks/freshdesk', [
                'ticket_id' => $ticketId,
                'event_type' => 'agent_replied',
                'event_timestamp' => $at,
                'conversation_data' => [
                    'actor_id' => 501,
                    'created_at' => $at,
                    'updated_at' => $at,
                ],
            ]);
    }

    private function created(int $ticketId, string $createdAt)
    {
        return $this->withBasicAuth('qa-user', 'qa-password')
            ->postJson('/api/webhooks/freshdesk', [
                'ticket_id' => $ticketId,
                'event_type' => 'ticket_created',
                'event_timestamp' => $createdAt,
                'ticket_data' => $this->ticketData($ticketId, $createdAt),
            ]);
    }

    private function ticketData(int $ticketId, string $createdAt): array
    {
        return [
            'id' => $ticketId,
            'status' => 'Open',
            'priority' => 'High',
            'ticket_type' => 'VIP',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }
}
