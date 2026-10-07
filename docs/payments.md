# Payment recovery and account credits

Oldora verifies Cryptomus callbacks using `CRYPTOMUS_API_KEY`. Invoice amounts,
currencies, order IDs and payment IDs must match before any balance is credited.
Provider info requests use the existing merchant/API authentication with TLS
verification enabled. Browser redirects never prove that a payment succeeded.

Cryptomus uses its API key locally to calculate request signatures and verify
callback signatures. Supply a usable signing key through a secure runtime
binding. An opaque placeholder that an HTTP proxy replaces only in outgoing
headers cannot perform these local calculations. Confirm that the environment's
secret mechanism supports locally computed signatures before enabling live
payments; do not substitute a made-up key or disable signature verification.

Credit addition and invoice completion run in one database transaction. A locked
invoice prevents repeated or concurrent callbacks from granting credits twice.
Late failed/check callbacks and a concurrent checkout response cannot overwrite
a paid invoice. Successful payments also mark the account as paid.

Configure the merchant callback as the public HTTPS URL `/webhook.php`, and run
the recovery worker every minute even when the customer closes the payment tab:

```cron
* * * * * cd /path/to/oldora && php cron_payments.php
```

The worker checks up to five due invoices per run, including recent failed or
uncertain creation attempts, so a late confirmation can recover automatically.
Provider errors retain the local invoice and its credits without granting them;
retries back off up to 15 minutes. `last_checked_at`, `next_check_at`,
`reconcile_attempts`, and `last_error` help administrators diagnose recovery.
Failed/uncertain invoices older than seven days need an administrator to verify
them against provider info; pending invoices continue to be checked regardless
of age. Customer polling is throttled to one provider check per 30 seconds.

For an HTTP scheduler, use `POST /cron_payments.php` with the `X-Cron-Secret`
header matching `CRON_SECRET`. Keep secret values out of URLs and logs. The
worker reports `configured: false` when provider credentials are unavailable.

The return page displays the current account balance from the database and
updates the shared balance view after confirmation. Plan prices are server-owned,
and custom payments calculate credits in integer cents at $0.70 per credit.

Older **unpaid** invoices with no stored credits can recover only when their
server-stored plan and exact price match a known plan, or their custom amount
can be priced safely. Older invoices already marked paid are never credited
again automatically: their historical balance may already include the payment.
Orders duplicated in a legacy table are rejected rather than credited to an
arbitrary account. Such records need a separate audited correction.

Run the payment tests only in the development environment:

```sh
php tests/payments.php
```

These tests create and remove a separate MariaDB test database, use fixture
provider responses, and never contact or create a live payment. They verify
signed/tampered callback checks, strict provider matching, exact credit pricing,
atomic rollback, late-payment recovery, retry throttling, legacy recovery, and
four concurrent callback processes crediting the account exactly once.
