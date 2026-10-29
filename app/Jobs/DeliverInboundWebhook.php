<?php

namespace App\Jobs;

use App\Exceptions\UnsafeWebhookUrlException;
use App\Exceptions\WebhookDnsResolutionException;
use App\Models\InboundEmail;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDeliveryService;
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

/**
 * Fans an inbound email out to every active endpoint subscribed to the
 * "inbound.received" event, using the same signature scheme and delivery
 * records as outbound event webhooks.
 */
class DeliverInboundWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const EVENT_TYPE = 'inbound.received';

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function __construct(
        public int $inboundEmailId,
        public ?int $webhookEndpointId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->inboundEmailId.':'.($this->webhookEndpointId ?? 'fanout');
    }

    public function handle(WebhookUrlGuard $urlGuard): void
    {
        $inbound = InboundEmail::query()->with(['project', 'thread'])->find($this->inboundEmailId);

        if (! $inbound || ! $inbound->project) {
            return;
        }

        if ($this->webhookEndpointId === null) {
            $inbound->project->webhookEndpoints()
                ->where('status', 'active')
                ->whereJsonContains('events', self::EVENT_TYPE)
                ->pluck('id')
                ->each(fn (int $endpointId) => app(WebhookDeliveryService::class)->dispatchJob(
                    (new self($inbound->id, $endpointId))->onConnection($this->connection)->onQueue($this->queue),
                ));

            return;
        }

        $endpoint = $inbound->project
            ->webhookEndpoints()
            ->whereKey($this->webhookEndpointId)
            ->where('status', 'active')
            ->whereJsonContains('events', self::EVENT_TYPE)
            ->first();

        if (! $endpoint) {
            return;
        }

        if ($endpoint->deliveries()->where('status', 'ok')->where('payload->id', 'evt_inbound_'.$inbound->id)->exists()) {
            return;
        }

        $this->deliver($endpoint, $inbound, $urlGuard);
    }

    private function deliver(WebhookEndpoint $endpoint, InboundEmail $inbound, WebhookUrlGuard $urlGuard): void
    {
        $payload = $this->payload($inbound);
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
            $response = Http::withHeaders($this->headers($endpoint, $inbound, $body))
                ->setHandler(new CurlHandler)
                ->withOptions($requestOptions)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $delivery = $this->recordDelivery(
                endpoint: $endpoint,
                payload: $payload,
                status: $response->successful() ? 'ok' : 'fail',
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
     * @return array<string, mixed>
     */
    private function payload(InboundEmail $inbound): array
    {
        return [
            'id' => 'evt_inbound_'.$inbound->id,
            'type' => self::EVENT_TYPE,
            'created_at' => $inbound->received_at->toIso8601String(),
            'data' => [
                'inbound_email' => [
                    'id' => $inbound->public_id,
                    'from' => trim(($inbound->from_name ? $inbound->from_name.' ' : '').'<'.$inbound->from_email.'>'),
                    'to' => $inbound->to_email,
                    'subject' => $inbound->subject,
                    'text' => $inbound->text,
                    'html' => $inbound->html,
                    'headers' => $inbound->headers,
                    'attachments' => $inbound->attachments,
                    'message_id' => $inbound->message_id,
                    'in_reply_to' => $inbound->in_reply_to,
                    'thread_id' => $inbound->thread?->public_id,
                    'received_at' => $inbound->received_at->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(WebhookEndpoint $endpoint, InboundEmail $inbound, string $body): array
    {
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->signing_secret);

        return [
            'Larasend-Event-Id' => 'evt_inbound_'.$inbound->id,
            'Larasend-Event-Type' => self::EVENT_TYPE,
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
            'event_type' => self::EVENT_TYPE,
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

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
