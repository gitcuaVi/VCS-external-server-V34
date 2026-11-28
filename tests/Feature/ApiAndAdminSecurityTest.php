<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiAndAdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freshdesk.basic_auth.username' => 'fd-app',
            'freshdesk.basic_auth.password' => 'fd-app-secret',
            'freshdesk.admin_api_basic_auth.username' => 'ops-api',
            'freshdesk.admin_api_basic_auth.password' => 'ops-api-secret',
        ]);
    }

    // #11 ---------------------------------------------------------------

    public function test_admin_api_rejects_webhook_credential_and_accepts_its_own(): void
    {
        $this->withBasicAuth('fd-app', 'fd-app-secret')
            ->getJson('/api/admin/sla-policies')
            ->assertUnauthorized();

        $this->withBasicAuth('ops-api', 'ops-api-secret')
            ->getJson('/api/admin/sla-policies')
            ->assertOk();
    }

    public function test_admin_api_is_closed_when_not_configured(): void
    {
        config([
            'freshdesk.admin_api_basic_auth.username' => null,
            'freshdesk.admin_api_basic_auth.password' => null,
        ]);

        $this->withBasicAuth('fd-app', 'fd-app-secret')
            ->getJson('/api/admin/sla-policies')
            ->assertUnauthorized();
    }

    public function test_admin_api_is_closed_when_it_reuses_the_webhook_credential(): void
    {
        config([
            'freshdesk.admin_api_basic_auth.username' => 'fd-app',
            'freshdesk.admin_api_basic_auth.password' => 'fd-app-secret',
        ]);

        $this->withBasicAuth('fd-app', 'fd-app-secret')
            ->getJson('/api/admin/sla-policies')
            ->assertUnauthorized();
    }

    public function test_webhook_credential_still_works_for_ticket_api(): void
    {
        $this->withBasicAuth('fd-app', 'fd-app-secret')
            ->getJson('/api/tickets/99999/history')
            ->assertOk();
    }

    // #12 ---------------------------------------------------------------

    public function test_repeated_failed_logins_are_blocked_but_valid_credential_still_passes(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->withBasicAuth('fd-app', "wrong-{$i}")
                ->getJson('/api/tickets/99999/history')
                ->assertUnauthorized();
        }

        $this->withBasicAuth('fd-app', 'wrong-again')
            ->getJson('/api/tickets/99999/history')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // Legitimate caller behind the same IP is not locked out.
        $this->withBasicAuth('fd-app', 'fd-app-secret')
            ->getJson('/api/tickets/99999/history')
            ->assertOk();
    }

    public function test_authenticated_requests_are_rate_limited_per_minute(): void
    {
        config(['freshdesk.api_rate_limits.tickets' => 2]);

        $this->withBasicAuth('fd-app', 'fd-app-secret')->getJson('/api/tickets/99999/history')->assertOk();
        $this->withBasicAuth('fd-app', 'fd-app-secret')->getJson('/api/tickets/99999/history')->assertOk();
        $this->withBasicAuth('fd-app', 'fd-app-secret')->getJson('/api/tickets/99999/history')->assertStatus(429);
    }

    // #13, #14 ----------------------------------------------------------

    public function test_viewer_cannot_read_logs_or_raw_spool_payloads(): void
    {
        $viewer = $this->asAdmin(AdminUser::ROLE_VIEWER);

        $viewer->get('/admin/system-logs')->assertForbidden();
        $viewer->get('/admin/system-logs/download?file=laravel.log')->assertForbidden();
        $viewer->get('/admin/spool-files/view?type=freshdesk&path=ready/x.json')->assertForbidden();
        $viewer->get('/admin/spool-files?type=freshdesk&folder=ready')->assertOk();
    }

    public function test_manager_can_read_logs(): void
    {
        $this->asAdmin(AdminUser::ROLE_SLA_MANAGER)->get('/admin/system-logs')->assertOk();
    }

    public function test_log_download_only_serves_listed_log_files(): void
    {
        $logPath = storage_path('logs');
        File::ensureDirectoryExists($logPath);
        $logFile = 'qa-download-'.Str::random(8).'.log';
        $otherFile = 'qa-download-'.Str::random(8).'.txt';
        File::put($logPath.DIRECTORY_SEPARATOR.$logFile, 'log line');
        File::put($logPath.DIRECTORY_SEPARATOR.$otherFile, 'not a log');

        try {
            $manager = $this->asAdmin(AdminUser::ROLE_SUPER_ADMIN);

            $manager->get("/admin/system-logs/download?file={$logFile}")->assertOk()->assertDownload($logFile);
            $manager->get("/admin/system-logs/download?file={$otherFile}")->assertSessionHas('error');
            $manager->get('/admin/system-logs/download?file=../.env')->assertSessionHas('error');
            $manager->get('/admin/system-logs/download?file[]=x')->assertSessionHas('error');
        } finally {
            File::delete([$logPath.DIRECTORY_SEPARATOR.$logFile, $logPath.DIRECTORY_SEPARATOR.$otherFile]);
        }
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
