<?php

namespace App\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for database retention. Scheduled daily (routes/console.php).
 * Retention periods live in config/retention.php and config/rocketchat_audit.php.
 */
class PruneDataCommand extends Command
{
    public const PARTS = ['outbound', 'receipts', 'rocketchat'];

    /** Rows the SLA overdue scanner relies on to avoid flagging the same due date twice. */
    private const OVERDUE_SCANNER_SOURCE = 'sla-overdue-scanner';

    protected $signature = 'data:prune
        {--only= : Comma separated parts to prune: outbound, receipts, rocketchat}
        {--dry-run : Count the rows that would be deleted without deleting them}';

    protected $description = 'Delete expired outbound operations, webhook receipts and Rocket.Chat delivery history';

    public function handle(): int
    {
        $parts = $this->selectedParts();
        if ($parts === null) {
            $this->error('--only accepts: '.implode(', ', self::PARTS));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = [];
        foreach ($parts as $part) {
            $result[$part] = match ($part) {
                'outbound' => $this->pruneOutbound($dryRun),
                'receipts' => $this->pruneReceipts($dryRun),
                'rocketchat' => $this->pruneRocketChat($dryRun),
            };
        }

        $verb = $dryRun ? 'Dry run, would delete' : 'Deleted';
        foreach ($result as $part => $count) {
            $this->info("{$verb} {$count} {$part} row(s).");
        }
        Log::info('Data prune completed', [...$result, 'dry_run' => $dryRun]);

        return self::SUCCESS;
    }

    /** @return list<string>|null null when --only contains an unknown part */
    private function selectedParts(): ?array
    {
        $only = trim((string) $this->option('only'));
        if ($only === '') {
            return self::PARTS;
        }

        $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', $only)))));
        if ($parts === [] || array_diff($parts, self::PARTS) !== []) {
            return null;
        }

        return $parts;
    }

    private function pruneOutbound(bool $dryRun): int
    {
        $completedCutoff = $this->cutoff('retention.outbound_completed_days', 30);
        $failedCutoff = $this->cutoff('retention.outbound_failed_days', 90);

        $query = fn (): Builder => DB::table('freshdesk_outbound_operations')
            ->where(function (Builder $expired) use ($completedCutoff, $failedCutoff): void {
                $expired->where(fn (Builder $q) => $q
                    ->whereIn('state', ['completed', 'superseded'])
                    ->where('updated_at', '<', $completedCutoff))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('state', 'failed')
                        ->where('updated_at', '<', $failedCutoff));
            })
            ->where(fn (Builder $q) => $q
                ->whereNull('payload->source')
                ->orWhere('payload->source', '!=', self::OVERDUE_SCANNER_SOURCE));

        return $this->deleteInBatches($query, 'operation_id', $dryRun);
    }

    private function pruneReceipts(bool $dryRun): int
    {
        $cutoff = $this->cutoff('retention.webhook_receipt_days', 30);

        return $this->deleteInBatches(
            fn (): Builder => DB::table('freshdesk_webhook_receipts')->where('committed_at', '<', $cutoff),
            'receipt_id',
            $dryRun
        );
    }

    private function pruneRocketChat(bool $dryRun): int
    {
        $cutoff = $this->cutoff('rocketchat_audit.retention_days', 90);

        return $this->deleteInBatches(
            fn (): Builder => DB::table('rocket_chat_delivery_statuses')->where('attempted_at', '<', $cutoff),
            'id',
            $dryRun
        );
    }

    private function cutoff(string $configKey, int $defaultDays): CarbonInterface
    {
        return now()->utc()->subDays(max(1, (int) config($configKey, $defaultDays)));
    }

    /**
     * Delete matching rows a batch at a time so no statement holds locks for long.
     *
     * @param  callable(): Builder  $query  fresh query for the rows to delete
     */
    private function deleteInBatches(callable $query, string $key, bool $dryRun): int
    {
        if ($dryRun) {
            return $query()->count();
        }

        $batchSize = max(1, (int) config('retention.batch_size', 1000));
        $deleted = 0;

        do {
            $ids = $query()->orderBy($key)->limit($batchSize)->pluck($key);
            $count = $ids->isEmpty()
                ? 0
                : DB::table($query()->from)->whereIn($key, $ids)->delete();
            $deleted += $count;
        } while ($ids->count() === $batchSize && $count > 0);

        return $deleted;
    }
}
