<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A webhook arrived for a ticket that is not tracked yet. The spool retries it
 * so it can be processed once ticket_created is persisted. This is an expected
 * wait, not a processing failure.
 */
class TicketNotTrackedYetException extends RuntimeException {}
