<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Freshdesk rejected the call permanently (400/403/404/405/422, missing ticket,
 * unsupported or malformed operation). Retrying cannot succeed, so the outbound
 * operation must stop instead of being re-queued.
 */
class FreshdeskPermanentApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
