<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\Webhooks\DurableWebhookSpool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSpoolTicketSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v34-spool-search-'.bin2hex(random_bytes(6));
        config([
            'freshdesk_spool.root' => $this->root,
            'freshdesk_spool.require_directory_fsync' => false,
        ]);

        $spool = app(DurableWebhookSpool::class);
        $spool->accept($this->payload(19895, 'agent_replied'), 'search-a');
        $spool->accept($this->payload(19895, 'status_changed'), 'search-b');
        $spool->accept($this->payload(20001, 'status_changed'), 'search-c');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_list_shows_ticket_id_and_event_type_of_each_file(): void
    {
        $response = $this->asAdmin(AdminUser::ROLE_VIEWER)
            ->getJson('/admin/spool-files?type=freshdesk&folder=ready')
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('ticket_id', null)
            ->assertJsonPath('ticket_counts', null);

        $this->assertEqualsCanonicalizing(
            [[19895, 'agent_replied'], [19895, 'status_changed'], [20001, 'status_changed']],
            collect($response->json('files'))->map(fn (array $f): array => [$f['ticket_id'], $f['event_type']])->all()
        );
    }

    public function test_ticket_filter_returns_only_that_ticket_and_counts_every_step(): void
    {
        $response = $this->asAdmin(AdminUser::ROLE_SLA_MANAGER)
            ->getJson('/admin/spool-files?type=freshdesk&folder=ready&ticket_id=19895')
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('ticket_id', 19895)
            ->assertJsonPath('ticket_counts.ready', 2)
            ->assertJsonPath('ticket_counts.quarantine', 0)
            ->assertJsonPath('truncated', false);

        $this->assertSame([19895, 19895], array_column($response->json('files'), 'ticket_id'));
    }

    public function test_ticket_filter_with_no_match_returns_empty_list(): void
    {
        $this->asAdmin(AdminUser::ROLE_VIEWER)
            ->getJson('/admin/spool-files?type=freshdesk&folder=ready&ticket_id=99999')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('files', [])
            ->assertJsonPath('ticket_counts.ready', 0);
    }

    public function test_ticket_filter_rejects_non_numeric_input(): void
    {
        $viewer = $this->asAdmin(AdminUser::ROLE_VIEWER);

        $viewer->getJson('/admin/spool-files?type=freshdesk&folder=ready&ticket_id=19895abc')->assertStatus(422);
        $viewer->getJson('/admin/spool-files?type=freshdesk&folder=ready&ticket_id[]=1')->assertStatus(422);
    }

    private function payload(int $ticketId, string $eventType): array
    {
        $at = now()->subMinute()->toIso8601ZuluString();

        return [
            'ticket_id' => $ticketId,
            'event_type' => $eventType,
            'event_timestamp' => $at,
            'ticket_data' => ['status' => 2, 'updated_at' => $at],
        ];
    }

    private function asAdmin(string $role): self
    {
        $admin = AdminUser::create([
            'name' => "QA {$role}",
            'username' => "qa_{$role}_".Str::random(6),
            'email' => Str::random(8).'@example.test',
            'password' => 'secret-password',
            'role' => $role,
            'is_active' => true,
        ]);

        return $this->withSession(['admin_logged_in' => true, 'admin_user_id' => $admin->id]);
    }
}
