<?php

namespace App\Jobs;

use App\Exceptions\UnsafeWebhookUrlException;
use App\Exceptions\WebhookDnsResolutionException;
use App\Models\EmailEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\EventEvidence;
use App\Services\WebhookUrlGuard;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeliverWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return $this->webhookEndpointId.':'.$this->emailEventId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $webhookEndpointId,
        public int $emailEventId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WebhookUrlGuard $urlGuard, EventEvidence $evidence): void
    {
        $endpoint = WebhookEndpoint::query()->find($this->webhookEndpointId);
        $event = EmailEvent::query()
            ->with(['email.recipients'])
            ->find($this->emailEventId);

        if (! $endpoint || ! $event || $endpoint->status !== 'active') {
            return;
        }

        $canonical = $evidence->for($event);

        if (! in_array($canonical['type'], $endpoint->events ?? [], true)) {
            return;
        }

        if ($endpoint->project_id !== $event->email?->project_id) {
            return;
        }

        if ($endpoint->deliveries()->where('status', 'ok')->where('payload->id', $canonical['id'])->exists()) {
            return;
        }

        $payload = $this->payload($event, $canonical);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $startedAt = hrtime(true);

        try {
            $requestOptions = $urlGuard->requestOptions($endpoint->url);
        } catch (UnsafeWebhookUrlException|WebhookDnsResolutionException $exception) {
            $this->recordDelivery($endpoint, $payload, 'fail', null, 0, $exception->getMessage());

            if ($exception instanceof UnsafeWebhookUrlException) {
                $endpoint->forceFill(['status' => 'paused'])->save();

                return;
            }

            $this->releaseForRetry($exception->getMessage());

            return;
        }

        try {
            $response = Http::withHeaders($this->headers($endpoint, $payload, $body))
                ->setHandler(new CurlHandler)
                ->withOptions($requestOptions)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $status = $response->successful() ? 'ok' : 'fail';

            $delivery = $this->recordDelivery(
                endpoint: $endpoint,
                payload: $payload,
                status: $status,
                httpStatus: $response->status(),
                latencyMs: $this->latencyMs($startedAt),
                responseBody: Str::limit($response->body(), 2000, ''),
            );

            if ($response->successful()) {
                $endpoint->forceFill(['last_delivered_at' => $delivery->delivered_at])->save();

                return;
            }

            $this->releaseForRetry("Webhook endpoint returned HTTP {$response->status()}.");
        } catch (ConnectionException $exception) {
            $this->recordDelivery(
                endpoint: $endpoint,
                payload: $payload,
                status: 'fail',
                httpStatus: null,
                latencyMs: $this->latencyMs($startedAt),
                responseBody: Str::limit($exception->getMessage(), 2000, ''),
            );

            $this->releaseForRetry($exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $canonical
     * @return array<string, mixed>
     */
    private function payload(EmailEvent $event, array $canonical): array
    {
        $email = $event->email;

        return [
            'id' => $canonical['id'],
            'type' => $canonical['type'],
            'created_at' => $canonical['created_at'],
            'data' => [
                'email' => $email ? [
                    'id' => $email->public_id,
                    'status' => $email->status,
                    'subject' => $email->subject,
                    'from' => trim(($email->from_name ? $email->from_name.' ' : '').'<'.$email->from_email.'>'),
                    'to' => $email->recipients->where('type', 'to')->pluck('email')->values()->all(),
                    'ses_message_id' => $email->ses_message_id,
                ] : null,
                'event' => $canonical,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function headers(WebhookEndpoint $endpoint, array $payload, string $body): array
    {
        $timestamp = (string) now()->getTimestamp();
        $signedPayload = $timestamp.'.'.$body;
        $signature = hash_hmac('sha256', $signedPayload, $endpoint->signing_secret);

        return [
            'Larasend-Event-Id' => $payload['id'],
            'Larasend-Event-Type' => $payload['type'],
            'Larasend-Signature' => "t={$timestamp},v1={$signature}",
            'User-Agent' => 'Larasend-Webhooks/1.0',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordDelivery(
        WebhookEndpoint $endpoint,
        array $payload,
        string $status,
        ?int $httpStatus,
        int $latencyMs,
        string $responseBody,
    ): WebhookDelivery {
        return WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'public_id' => 'whd_'.Str::lower(Str::random(16)),
            'event_type' => $payload['type'],
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs,
            'status' => $status,
            'payload' => $payload,
            'response_body' => $responseBody,
            'delivered_at' => now(),
        ]);
    }

    private function latencyMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function releaseForRetry(string $reason): void
    {
        $attempt = max($this->attempts() - 1, 0);
        $delay = $this->backoff()[$attempt] ?? last($this->backoff());

        if ($this->attempts() < $this->tries) {
            $this->release($delay);

            return;
        }

        throw new RuntimeException($reason);
    }

    /**
     * Handle a job failure after retries are exhausted.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
