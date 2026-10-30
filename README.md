# Larasend

[![Boop! it to Laravel Cloud](https://deploy.laravel.cloud/boop.svg)](https://deploy.laravel.cloud/github/savvyagents/larasend)

Larasend is a self-hosted transactional email platform for Laravel teams. It gives you a clean HTTP API, dashboard, activity log, API keys, delivery events, suppressions, and a Laravel mail transport while sending through your own Amazon SES account or Cloudflare Email Service.

![Larasend activity dashboard](.github/dashboard.png)

Larasend is built with Laravel, Inertia, Vue, PostgreSQL, Redis, and Docker.

## Why Larasend?

- Use Laravel Mail with a Larasend driver instead of wiring every app directly to a provider.
- Keep email activity, previews, headers, metrics, retries, API keys, and suppressions in one place.
- Send through Amazon SES with your own verified domains, configuration sets, and IAM controls, or through Cloudflare Email Service with a single scoped API token.
- Self-host the control plane so your email logs and metadata stay in infrastructure you control.
- Run production with Docker Compose, or hack on the app locally like a normal Laravel project.

## Features

- Simple `POST /api/emails` API with API key authentication.
- Laravel mail transport package for drop-in `MAIL_MAILER=larasend` usage.
- Project-scoped API keys with scopes, expiration, rotation, and last-used metadata.
- Activity dashboard with status filters, search, grouped timeline, inspector preview, headers, metrics, and resend.
- Provider choice per source: Amazon SES or Cloudflare Email Service.
- Inbound email on Cloudflare sources: one click deploys a Worker and routing rule, received mail lands in the dashboard and fires `inbound.received` webhooks.
- Team inbox: inbound and outbound mail threaded into conversations with reply, compose, forward, attachments, rich text, drafts, snooze, internal notes, and keyboard shortcuts.
- SES identity setup with DKIM record guidance; Cloudflare domain verification via DNS checks.
- SES event ingestion for delivery, bounce, complaint, open, click, and suppression events.
- Hourly Cloudflare suppression-list sync (Cloudflare has no event webhooks or open/click tracking).
- Suppression list tracking for bounces and complaints.
- Workspace members and project permissions.
- Source health, provider quota sync, and delivery guardrails for verified domains, suppressions, and complaint rate.
- Docker image and production-ready queue worker setup.

## How It Works

1. Your Laravel app sends mail through the Larasend Laravel transport or directly through the HTTP API.
2. Larasend validates the request, stores the MIME payload and metadata, and queues delivery.
3. The Larasend queue worker sends the raw email through the source's provider — Amazon SES (HTTPS API) or Cloudflare Email Service (authenticated SMTP).
4. SES publishes delivery/bounce/complaint/open/click events back to Larasend through the SES webhook. Cloudflare has no event webhooks: delivery state is recorded at send time and suppressions sync hourly from the Cloudflare account-level list.
5. Larasend updates the activity dashboard, metrics, suppressions, and webhook deliveries.

Larasend accepts API sends even when provider quota sync is stale or temporarily unavailable. The provider remains the final authority for send rejection.

## Requirements

- Docker and Docker Compose for production-style installs.
- PHP 8.4+ and Node.js 22+ for local development.
- PostgreSQL 17+.
- Redis 7+ if you use the bundled compose stack.
- An Amazon SES account with a verified sending domain, or a Cloudflare account on the Workers Paid plan with a domain onboarded for Email Sending.

## Quick Start With Docker

Create a deployment directory and download the production files:

```bash
mkdir larasend
cd larasend
curl -fsSL https://raw.githubusercontent.com/savvyagents/larasend/main/docker-compose.yml -o docker-compose.yml
curl -fsSL https://raw.githubusercontent.com/savvyagents/larasend/main/.env.example -o .env
```

Edit `.env`:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mail.example.com
DB_PASSWORD=change-me
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

Generate an application key:

```bash
docker compose pull
docker compose run --rm --entrypoint php app artisan key:generate --show
```

Paste the generated `base64:...` value into `APP_KEY` in `.env`, then start Larasend:

```bash
docker compose up -d
```

This starts everything Larasend needs: the web app (which runs migrations automatically on boot), the queue worker, the scheduler for background automation (DNS verification, quota refresh, suppression sync), PostgreSQL, and Redis.

Open `APP_URL`, create the first user, and follow onboarding. To confirm the install is healthy:

```bash
docker compose exec app php artisan larasend:doctor
```

## Amazon SES Setup

Larasend can use stored AWS credentials or, in production, an attached EC2 instance role.

The IAM principal used by Larasend needs SES permissions for:

```text
ses:SendEmail
ses:CreateEmailIdentity
ses:GetEmailIdentity
ses:GetAccount
```

Recommended setup:

1. Create or choose an SES verified domain, for example `mail.example.com`.
2. In Larasend, add the sending domain and copy the DKIM records.
3. Publish the DNS records in Route 53 or your DNS provider.
4. Configure an SES configuration set with an SNS event destination and save its name on the Larasend source. Enable Send, Delivery, Bounce, Complaint, Open, Click, Reject, Rendering Failure, and DeliveryDelay as needed.
5. Subscribe the Larasend source webhook URL shown in the setup screen to that SNS topic and complete subscription confirmation. Keep this URL secret.
6. Send a test email from Larasend and confirm delivery activity appears.

Configuration-set event publishing is required for reliable recovery when the SES send response is lost: Larasend supplies a server-controlled correlation value in `EmailTags`, and event publishing returns it in `mail.tags`. Identity-level feedback notifications omit these message tags, so a notification without an already known SES message ID may remain unresolved. See the [SES event publishing setup](https://docs.aws.amazon.com/ses/latest/dg/monitor-using-event-publishing.html) and [SNS event format](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-contents.html).

## Cloudflare Email Service Setup

Larasend can send through [Cloudflare Email Service](https://developers.cloudflare.com/email-service/) instead of SES. Sending happens over Cloudflare's authenticated SMTP endpoint; quota and suppressions sync over the Cloudflare REST API.

Requirements:

- The sending domain must be an active zone on your Cloudflare account (Cloudflare DNS).
- The account must be on the Workers Paid plan (includes 3,000 emails/month, then $0.35 per 1,000).
- Email Service is for transactional email only, which matches what Larasend is for.

Inbound email is supported on Cloudflare sources: enabling "Receive email" on a domain deploys a small Cloudflare Worker and points the zone's catch-all routing rule at it, so mail sent to any address on the zone apex lands in the Inbound section and fires `inbound.received` webhooks to your subscribed endpoints. The token additionally needs "Workers Scripts: Edit" and "Email Routing Rules: Edit" for this (added manually; the pre-filled link covers sending only).

Recommended setup:

1. Create an API token with the "Email Sending: Edit", "Zone: Read", and "DNS: Edit" permissions. The Larasend source settings include a pre-filled token creation link.
2. In Larasend, switch the source provider to Cloudflare and save the account ID and API token.
3. Add the sending domain in Larasend. Larasend onboards it for Email Sending through the Cloudflare zone API and publishes the required DNS records automatically; re-check DNS once they propagate.
4. Sync quota, then send a test email.

If you prefer a minimal token with only "Email Sending: Edit", onboard the domain manually in the Cloudflare dashboard (Compute & AI > Email Service > Email Sending) instead — Larasend then only verifies the records via DNS.

Differences from SES to be aware of:

- Cloudflare has no delivery-event webhooks and no open/click tracking. Delivery state is recorded from the SMTP response at send time.
- Suppressions (hard bounces, spam complaints) are managed on Cloudflare's account-level list and sync into Larasend hourly. This requires the Laravel scheduler, which the Docker stack runs automatically as the `scheduler` service; for non-Docker installs run `php artisan schedule:work` or add a cron entry calling `schedule:run`.
- Quota is a daily allowance rather than a rolling 24-hour window.

## Team Inbox

Every project gets a shared inbox at `/projects/{slug}/inbox` where inbound mail and your outbound sends are threaded into conversations (References/In-Reply-To chains first, normalized subject + shared participant as fallback).

- **Reply** goes out as the address the conversation was received on, with proper threading headers so external mail clients keep the thread intact.
- **Compose, forward, attachments** — start new conversations, forward the latest message (original attachments included), attach files up to 10 MB each.
- **Rich text** replies with plain-text alternatives generated automatically; unsent drafts persist locally per thread.
- **Snooze** hides a conversation until later today, tomorrow, or next week — a new inbound message wakes it immediately.
- **Internal notes** annotate the timeline for your team (amber cards, never emailed); read-only members can note even though they cannot send.
- **Keyboard shortcuts**: `j`/`k` navigate, `e` archive, `u` unread, `r` reply, `f` forward, `c` compose, `/` search.
- `inbound.received` webhook payloads include the conversation's `thread_id`.

Existing mail can be grouped retroactively with `php artisan larasend:thread-backfill`, and `php artisan db:seed --class=InboxDemoSeeder` seeds sample conversations for a demo.

## Sending Email Over HTTP

Create an API key in Larasend, then send:

```bash
curl -X POST https://mail.example.com/api/emails \
  -H "Authorization: Bearer ls_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "Acme <notifications@mail.example.com>",
    "to": ["person@example.com"],
    "subject": "Hello from Larasend",
    "html": "<h1>Hello</h1><p>This was sent through Larasend.</p>",
    "text": "Hello. This was sent through Larasend.",
    "tags": {
      "app": "billing"
    }
  }'
```

Supported fields include:

- `from`, `to`, `cc`, `bcc`, `reply_to`
- `subject`, `html`, `text`
- `template_id`, `variables`
- `attachments` with base64 content
- `headers` for allowed custom headers
- `tags` for searchable metadata
- `source_id` to pin a positive integer source ID within the API key's project; a source-bound key can only use its own source

Each attachment includes `filename` and base64-encoded `content`, with an optional `content_type`. To embed an image, add a unique `content_id` and reference the exact value from HTML:

```json
{
  "html": "<img src=\"cid:logo@example.com\" alt=\"Company logo\">",
  "attachments": [
    { "filename": "logo.png", "content": "<base64 image bytes>", "content_type": "image/png", "content_id": "logo@example.com" }
  ]
}
```

Content IDs use an ASCII address such as `logo@example.com`, without angle brackets or a `cid:` prefix, and must be unique within the email and at most 128 characters. Omitting `content_id` or setting it to `null` creates an ordinary attachment. Inline parts retain their original Content-ID in the archived `.eml` file. Dashboard previews display matching stored PNG/JPEG images up to 256 KiB each, 512 KiB total, and 4096 pixels per dimension.

### Connection Identity And Source Pinning

Before sending, use the same bearer key to inspect `GET /api/connection?source_id=12`. `source_id` must be a positive integer. A source-bound key can omit it; an unbound key can use the resolved default for discovery, then explicitly pin the returned source on sends and recovery requests.

```json
{
  "data": {
    "object": "connection",
    "contract_version": 2,
    "instance_id": "6b29ff3b-bd99-4f27-b201-ef034db1368a",
    "project_id": 3,
    "api_key_id": 8,
    "source_id": 12,
    "source_pinned": true,
    "capabilities": { "send": true, "read:activity": true },
    "idempotency_scope": "api_key+source+client_key"
  }
}
```

`instance_id` is the installation's durable UUID. Project, key, and source IDs are integers local to that installation. `source_pinned` indicates an explicit or key-bound source selection. Capabilities describe this key's permissions; discovering a connection does not grant sending or activity access. Save the connection identity with each integration's send records so configuration changes are visible.

### Idempotency And Read-Only Recovery

Send an `Idempotency-Key` header on `POST /api/emails`. The client key must be valid UTF-8 and at most 255 bytes. Its scope is the authenticated API key identity, resolved source, and client key. Reusing that scope with an identical validated payload returns the original email and `replayed: true`; a different payload returns HTTP `409` with `error.code: "idempotency_conflict"`. Object key order does not change identity. Explicitly selecting the same resolved `source_id` does not change the payload fingerprint.

HTTP `422` distinguishes `error.code: "recipient_suppressed"` for synchronous suppression from `error.code: "validation_failed"` for invalid input. Existing human-readable `message` and validation `errors` remain available.

An idempotent POST replay may dispatch an email that is still queued. After a lost HTTP response, recover through a GET instead:

```bash
curl 'https://mail.example.com/api/emails/lookup?source_id=12' \
  -H 'Authorization: Bearer ls_your_api_key' \
  -H 'Idempotency-Key: invoice-2026-0042'
```

Lookup requires `read:activity` and an exact match for the same API key identity, source, and client key. `source_id` is required for unbound keys; a source-bound key can infer its own source. A successful HTTP `200` response contains a bounded email summary and no message body:

```json
{
  "data": {
    "id": "em_example",
    "object": "email",
    "status": "queued",
    "source_id": 12,
    "created_at": "2026-10-30T10:00:00+00:00",
    "sent_at": null
  },
  "meta": { "matched_by": "idempotency_key", "read_only": true }
}
```

When no exact authorized record exists, HTTP `404` returns `data: null` and `error.code: "not_found_or_unresolved"`. This can mean the original request was never stored; it does not prove that sending is safe. Lookup never enqueues, sends, or resends an email. It still updates normal API key usage metadata.

Credential rotation creates a different API key identity. Changing the default source can also change idempotency scope. Neither change grants access to another key's lookup or makes POST replay safe. Retain the original connection identity and explicit source selection; do not use a new key or source to retry an ambiguous send automatically.

### Event History And Reconciliation

`GET /api/emails/{id}/events?limit=50&cursor=...` returns event evidence without repeatedly downloading email HTML or text. It requires `read:activity` and project/source authorization. `limit` accepts integers from 1 to 100 and defaults to 50. An inaccessible email returns `404`; a malformed cursor or one issued for another email returns `422`.

`data` is an array of the same canonical event objects delivered as webhook `data.event`. Each object has:

| Field | Meaning |
| --- | --- |
| `id` | Stable `evt_` event ID, shared with webhook IDs. Scope deduplication by installation identity plus event ID. |
| `object`, `contract_version` | `email_event` and the evidence contract version. |
| `type` | Exact subscription wire name listed below. |
| `occurred_at`, `created_at` | ISO 8601 occurrence time; `created_at` is its compatibility alias. Provider timestamps describe the provider occurrence; internal timestamps describe the recorded submission transition. |
| `recorded_at` | When Larasend stored the event, which can be later than occurrence time. |
| `email_id`, `source_id` | Email public ID and integer source ID. |
| `sequence` | Per-email publication sequence for version 2; `null` for legacy evidence. |
| `origin`, `attempt_id` | `internal`, `ses`, or `legacy`; internal submission attempt identifier when available, otherwise `null`. |
| `recipient`, `recipients` | A single attributed recipient when available, otherwise `null`; the available recipient list, possibly empty. |
| `url`, `user_agent`, `ip_address` | Available engagement evidence, otherwise `null`. URLs are restricted to their origin. |
| `details`, `payload` | Allowlisted structured evidence; `payload` is a compatibility alias of `details`, not a raw provider payload. |

Version 2 event IDs always identify the same occurrence, type, timestamps, and details, including across webhook retries. `meta.email_snapshot` carries the current mutable email `id`, `status`, and `source_id`; status is not immutable event evidence.

The response includes `meta.pagination` with `limit`, `has_more`, and `next_cursor`, and `meta.reconciliation` with `checkpoint`, `through_sequence`, `legacy_baseline_complete`, `completeness`, `retention_days`, `automatic_deletion`, and `limitations`.

Follow `next_cursor` while `has_more` is true. Only after finishing the page chain, persist its terminal `checkpoint`. Pass that checkpoint as `cursor` on the next poll. Cursors are opaque and scoped to one email; do not derive them from event IDs or timestamps. Publication sequences follow committed availability, so an old provider occurrence received late, or an orphan event linked later, appears after an earlier checkpoint. Timestamp order and numeric event ID order are not reconciliation watermarks.

Legacy rows retain their existing event IDs and wire types, with `contract_version: 1` and `sequence: null`. Cursor traversal includes a legacy baseline before advancing through version 2 publications; `legacy_baseline_complete` reports whether it has been traversed. Earlier lifecycle rows overwritten by old behavior cannot be reconstructed, and no historical transitions are fabricated.

`completeness` is `stored_events_only`. `limitations` includes `historical_overwritten_transitions_unrecoverable` and `unreceived_or_unresolved_provider_events_not_included`. Missing callbacks, unverifiable ownership, and provider events never received by Larasend remain gaps. `retention_days` reports the source policy: at least 731 days, or `0` for indefinite retention. `automatic_deletion` is `false`; this release does not add scheduled deletion. The retention policy does not establish that every provider event was received.

### Event Wire Names And Recipient Attribution

Subscribe to exact wire names on an active webhook endpoint in the same project:

| Origin | Wire names | Interpretation |
| --- | --- | --- |
| Internal submission | `submission_started`, `submission_accepted`, `submission_rejected` | An attempt started, the provider accepted submission, or submission was rejected. A rejection's details identify retryability; rejection alone does not mean final failure. |
| Internal terminal outcome | `delivery_uncertain`, `failed` | Submission may have reached the provider without a conclusive response, or the send reached final failure. Uncertainty does not authorize resending. |
| SES | `send`, `delivery`, `bounce`, `complaint`, `open`, `click`, `reject`, `delivery_delay`, `rendering_failure` | Provider event evidence. `send` means an SES Send event, distinct from internal `submission_accepted`. A delay is not final failure. |
| Later suppression | `suppress` | An observed suppression associated with an existing email. |

Existing `delivery`, `bounce`, `complaint`, `open`, `click`, `suppress`, and `inbound.received` subscriptions remain valid. Inbound webhooks retain their separate inbound envelope. A synchronously suppressed recipient causes HTTP `422` before an email is created: there is no fabricated email or outbound `suppress` event for that rejected request. A later provider suppression can produce associated suppression evidence; a suppression-list entry without a verifiable email association cannot establish that a particular email was sent.

SES DeliveryDelay is read from `deliveryDelay`, including its timestamp, delayed recipients, expiration, and available diagnostic evidence. Rendering Failure is read from `failure`. Structured failure and delay details retain useful classifications and status codes while omitting raw headers, credentials, message contents, and sensitive diagnostic text. Click URLs expose only scheme, host, and port; path, query, fragment, and user information are removed, with `details.url_redacted` indicating redaction. Consumers cannot recover action-link tokens or exact clicked paths from this API.

Recipient evidence is limited to what the provider reports. An event can name multiple recipients or omit them; `recipient` must not be treated as proof of a unique affected recipient when `recipients` contains multiple values. SES complaints can describe possible complainants rather than a uniquely identified person. Open/click signals do not establish that every original recipient engaged, and missing attribution is not filled by guessing.

Larasend passes server-controlled correlation tags during SES submission and checks source ownership before linking later events. Early events and later orphan reconciliation use the same identity checks and deduplication. A tag alone cannot attach an event from another source, and historical events without an exact verifiable match stay unresolved. Recovery never resubmits mail to discover its outcome.

### Outbound Webhooks And Signatures

In the project's Webhooks settings, create an endpoint with a publicly reachable HTTP(S) URL, select event subscriptions, mark it active, and save the signing secret shown once. SES-to-Larasend delivery and Larasend-to-your-endpoint delivery are separate setup steps. Running a queue worker is required for outbound delivery.

The existing outer envelope remains `id`, `type`, `created_at`, and `data`. Its ID, type, and occurrence time agree with `data.event`; `data.event` is identical to event-history evidence. `data.email` retains the existing email summary as a mutable snapshot, so its status can change between delivery attempts. Deduplicate using the event ID rather than a hash of the whole webhook body.

Requests include:

```text
Larasend-Event-Id: evt_42
Larasend-Event-Type: delivery
Larasend-Signature: t=1793354400,v1=<hex-hmac>
```

To verify, parse `t` and `v1`, then compute HMAC-SHA256 with the endpoint signing secret over `t + "." + original_request_bytes`. Compare hexadecimal signatures in constant time. Verify before parsing or re-encoding JSON: whitespace and key order are part of the signed bytes. Apply a timestamp tolerance suitable for your receiver to reject stale requests. The signature timestamp describes that HTTP attempt, not event occurrence time; retries receive a new signature.

Any HTTP `2xx` acknowledges delivery. Other responses and connection failures retry up to three total attempts, normally after 5 seconds and then 30 seconds; queue scheduling can add delay. Requests use a 5-second connection timeout and 10-second overall timeout, and do not follow redirects. An unsafe URL pauses the endpoint. Acknowledge only after durably storing or processing the event. Finite retries and possible duplicate or out-of-order callbacks make read-only event reconciliation part of a reliable integration.

Authenticated API endpoints share default limits of 60 requests/minute per IP before authentication and 120 requests/minute per bearer key afterward. Operators can configure these through `LARASEND_API_AUTH_RATE_LIMIT` and `LARASEND_API_RATE_LIMIT`. On HTTP `429`, honor `Retry-After`, then retry GET recovery with bounded exponential backoff and jitter. A failed read does not authorize a new send. Keep polling bounded and save checkpoints after complete page traversal.

### Compatible Rollout

Apply the additive migrations before running the new application code. Drain old running send workers before enabling version 2 traffic, then restart workers on the new release; old workers can still write the earlier mutable lifecycle representation. Already queued jobs retain compatible constructors. Do not claim version 2 immutable evidence while old workers remain active.

The rollout preserves published event IDs and subscriptions, performs no historical event backfill, and adds no automatic resending or history deletion. Preserve stored history and the durable installation identity when moving the installation. Recovery describes retained evidence and cannot repair missing historical occurrences.

## Laravel Mail Driver

Until the Laravel package is published to Packagist, install it from GitHub.

Add the package repository to your Laravel app's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/vijaythecoder/larasend-laravel"
        }
    ]
}
```

Install the package:

```bash
composer require larasend/laravel:^0.1
```

Add the mailer to `config/mail.php`:

```php
'larasend' => [
    'transport' => 'larasend',
],
```

Configure your app:

```env
MAIL_MAILER=larasend
MAIL_FROM_ADDRESS=notifications@mail.example.com
MAIL_FROM_NAME="Acme"

LARASEND_ENDPOINT=https://mail.example.com
LARASEND_API_KEY=ls_your_api_key
LARASEND_TIMEOUT=15
```

Clear cached config:

```bash
php artisan config:clear
```

Now use Laravel Mail normally:

```php
use Illuminate\Support\Facades\Mail;

Mail::raw('Hello from Laravel through Larasend.', function ($message) {
    $message
        ->to('person@example.com')
        ->subject('Larasend test');
});
```

You can also use the client directly:

```php
use Larasend\Laravel\Facades\Larasend;

$email = Larasend::emails()->send([
    'from' => 'Acme <notifications@mail.example.com>',
    'to' => ['person@example.com'],
    'subject' => 'Direct API send',
    'text' => 'Hello from Larasend.',
]);
```

## Local Development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

Or run the bundled development command, which starts the server, queue worker, scheduler, log tail, and Vite together:

```bash
composer run dev
```

The queue worker sends the email; the scheduler drives background automation (DNS verification, quota refresh, Cloudflare suppression sync). If you run processes individually instead of using `composer run dev`, start those two yourself:

```bash
php artisan queue:work
php artisan schedule:work
```

If anything misbehaves, run the health check — it verifies the app key, database, queue worker, scheduler, provider credentials, and domain DNS, and prints the fix next to each failure:

```bash
php artisan larasend:doctor
```

## Building The Docker Image

To build the app locally:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up --build -d
```

The GitHub workflow publishes images to GitHub Container Registry on pushes to `main` and version tags.

## Verification

Run the same checks used before shipping:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
npm run types:check
npm run lint:check
npm run build
```

## Security Notes

- Larasend stores AWS credentials and Cloudflare API tokens encrypted when you choose stored credentials.
- For production on AWS, prefer an instance role or task role over long-lived access keys.
- Scope Cloudflare API tokens to "Email Sending: Edit", "Zone: Read", and "DNS: Edit" (or only "Email Sending: Edit" if you onboard domains manually).
- API keys are only shown once at creation.
- Use project scopes and expiration dates for application API keys.
- Do not expose the SES webhook token publicly beyond the generated webhook URL.

Please report security issues privately before opening a public issue.

## Roadmap

- Packagist release for the Laravel driver.
- More provider adapters beyond Amazon SES and Cloudflare Email Service.
- Cloudflare OAuth as an alternative to pasted API tokens. Deferred because every self-hosted install would first need its own OAuth client registered in the Cloudflare dashboard (redirect URLs are fixed per client), so it adds admin setup before it removes user friction; the token flow needs zero prerequisites.
- Cloudflare delivery-event polling via the GraphQL Analytics API (late bounces and spam flags after the SMTP accept). Deferred because it needs zone-scoped Analytics Read, per-message ID correlation, and only adds marginal signal — Cloudflare has no open/click tracking either way.
- CloudFormation quick-create link for one-click SES IAM setup. Deferred because it requires a project-hosted template outside this repository.
- Deeper per-domain health and deliverability reporting.
- First-class deploy workflow for updating self-hosted Docker installations.
- More template authoring and preview tools.

## Contributing

Issues and pull requests are welcome. Before opening a PR, run the verification commands above and keep changes focused.

This project follows Laravel conventions closely: controllers stay thin, business logic lives in services/actions, tests use Pest, and frontend pages are built with Inertia and Vue.

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=savvyagents/larasend&type=Date)](https://www.star-history.com/#savvyagents/larasend&Date)

## License

Larasend is open-sourced software licensed under the MIT license.
