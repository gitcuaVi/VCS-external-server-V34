<?php

namespace App\Console\Commands;

use App\Models\FreshdeskOutboundOperation;
use App\Services\Queue\FreshdeskOutboundService;
use App\Services\Queue\OutboundRetryService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RetryFailedOutboundCommand extends Command
{
    protected $signature = 'outbound:retry-failed
        {--ticket= : Sync lại một ticket}
        {--since= : Sync lại mọi ticket có sync_sla failed từ thời điểm này}
        {--dry-run : Chỉ liệt kê, không xếp lịch}';

    protected $description = 'Queue a fresh sync_sla for tickets whose SLA sync ended in failed';

    public function handle(OutboundRetryService $retry): int
    {
        if (! $this->option('ticket') && ! $this->option('since')) {
            $this->error('Cần --ticket=ID hoặc --since="YYYY-MM-DD HH:MM".');

            return self::INVALID;
        }

        $ticketIds = $this->option('ticket')
            ? collect([(int) $this->option('ticket')])
            : FreshdeskOutboundOperation::query()
                ->where('operation_type', FreshdeskOutboundService::SYNC_SLA)
                ->where('state', 'failed')
                ->where('completed_at', '>=', Carbon::parse((string) $this->option('since')))
                ->distinct()
                ->pluck('ticket_id');

        if ($ticketIds->isEmpty()) {
            $this->info('Không có ticket nào cần sync lại.');

            return self::SUCCESS;
        }

        foreach ($ticketIds as $ticketId) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] {$ticketId}");

                continue;
            }

            try {
                $retry->resyncTicket((int) $ticketId);
                $this->line("Đã xếp lịch sync lại: {$ticketId}");
            } catch (InvalidArgumentException $exception) {
                $this->warn("{$ticketId}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
