<?php

namespace App\Services\Webhooks;

use Carbon\Carbon;

/**
 * Release 1 tracks only tickets created at or after GO_LIVE_TIMESTAMP.
 * Shared by the single-webhook and batch ingestion paths.
 */
class GoLiveFilter
{
    public static function isActive(): bool
    {
        return (bool) config('freshdesk.enable_legacy_ticket_filter')
            && filled(config('freshdesk.go_live_timestamp'));
    }

    /** True when the filter is active and the ticket was created before go-live. */
    public static function isBeforeGoLive(mixed $createdAt): bool
    {
        if (! self::isActive() || ! is_string($createdAt) || trim($createdAt) === '') {
            return false;
        }

        return Carbon::parse($createdAt)->lessThan(Carbon::parse(config('freshdesk.go_live_timestamp')));
    }
}
