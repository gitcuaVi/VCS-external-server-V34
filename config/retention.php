<?php

/*
|--------------------------------------------------------------------------
| Data retention (php artisan data:prune, daily at 02:30)
|--------------------------------------------------------------------------
| Rocket.Chat delivery history keeps its existing setting:
| rocketchat_audit.retention_days (ROCKETCHAT_AUDIT_RETENTION_DAYS, default 90).
|
| Business data (tickets, ticket_events, histories, metrics) and the admin
| audit log are never pruned.
*/

return [
    // Freshdesk outbound operations in state completed / superseded.
    'outbound_completed_days' => (int) env('OUTBOUND_RETENTION_DAYS', 30),

    // Failed operations stay longer so they can still be retried from the admin page.
    'outbound_failed_days' => (int) env('OUTBOUND_FAILED_RETENTION_DAYS', 90),

    // Webhook receipts only guard against re-processing a spool file,
    // and processed spool files are removed after about an hour.
    'webhook_receipt_days' => (int) env('WEBHOOK_RECEIPT_RETENTION_DAYS', 30),

    // Rows deleted per statement, to keep each lock short.
    'batch_size' => 1000,
];
