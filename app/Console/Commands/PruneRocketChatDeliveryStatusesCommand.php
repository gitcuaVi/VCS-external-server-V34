<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Kept for existing runbooks. Retention now runs through data:prune.
 */
class PruneRocketChatDeliveryStatusesCommand extends Command
{
    protected $signature = 'rocketchat-audit:prune';

    protected $description = 'Delete expired RocketChat delivery status records (alias of data:prune --only=rocketchat)';

    public function handle(): int
    {
        return $this->call('data:prune', ['--only' => 'rocketchat']);
    }
}
