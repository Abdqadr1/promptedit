# PromptEdit

Signed-in users generate images through the Pixelforge API (async, priced per token, sometimes failing). Built with Laravel 13, Inertia v3 and Vue 3.

## Setup

```bash
composer setup
php artisan migrate:fresh --seed   # seeds test@example.com and admin@example.com (password: "password")
php artisan storage:link
composer dev                       # server, queue worker, scheduler, logs, vite
```

Set `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` in `.env`. Create one Pixelforge key per environment. Point that key's webhook URL at `https://<your-host>/webhooks/pixelforge`. For local work, use a tunnel such as cloudflared or ngrok, because Pixelforge rejects localhost.

The login page (also the homepage) has **Sample user** and **Admin** buttons that fill in the seeded demo credentials. Run `php artisan db:seed` on any environment where you want those users to exist. Set `DEMO_LOGINS=false` to hide the buttons.

To make an existing user an admin, run `php artisan admin:grant you@example.com`. Add `--revoke` to remove access.

## How money works

- **Unit.** Every amount is an integer count of **nano-dollars** (1e-9 USD). That matches the 9-decimal precision of `GET /v1/usage`, so totals add up exactly and no floating point is involved.
- **Provider cost.** The cost is Σ tokens × rate, using the `rates` stored on each generation. Those rates are fixed when the generation is created. Pixelforge never returns a price, so the app computes it from `usage`.
- **Charge.** The charge is the provider cost × 1.25, **rounded up to the next nano-dollar**. Rounding is at most $0.000000001 per generation and always in the house's favour. Customers see 4 decimal places, and the exact value is in a tooltip.
- **Estimate.** Before you submit, you see a min–max range. Text tokens are ±15%, reasoning tokens span a range, and output and reference-image tokens are exact.
- **When the charge is written.** The actual charge is written once, when a generation moves into `succeeded`. Failed, blocked and never-accepted generations cost $0.

## Robustness

- **Submission.** Submission runs in a queued job (`SubmitGeneration`).
    - On 429 (rate limit or concurrency limit) and 503, the job releases itself, honours `Retry-After` and adds jitter. It keeps trying for up to 30 minutes.
    - The local ULID is sent as the `Idempotency-Key`, so a retry after a lost response can never create a second billed job.
- **Webhooks** (`POST /webhooks/pixelforge`).
    - Each webhook's HMAC signature is verified over the raw body, with a 5-minute tolerance.
    - Events are deduplicated by event id. The dedupe record and the state change commit in one transaction.
    - Webhooks are matched by Pixelforge id, or by `metadata.local_id` when a webhook beats the create response.
- **State machine.** One state applier handles every state change. Status only moves forward, and `succeeded` and `failed` are final, so duplicate or out-of-order events cannot change a finished generation.
- **Reconciler.** `generations:reconcile` runs every minute. It polls Pixelforge for any in-flight generation that hasn't been synced for 2 minutes, which covers lost webhooks.
- **Images.** Images are copied to our own storage because Pixelforge's signed URLs expire after 24 hours.

## Admin report

`/admin/report` (admins only) shows the following per UTC day and per user:

- spend
- provider cost
- profit

Each day's provider cost is compared with Pixelforge's `GET /v1/usage`, using the same "UTC day completed" rule.

## Tests

```bash
composer test
```
