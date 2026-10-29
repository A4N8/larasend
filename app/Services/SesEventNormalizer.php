<?php

namespace App\Services;

use App\Events\EmailActivityUpdated;
use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Source;
use App\Models\Suppression;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SesEventNormalizer
{
    public function __construct(private WebhookDeliveryService $webhookDeliveryService) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Source $source, array $payload, ?string $providerMessageId = null): ?EmailEvent
    {
        $message = $payload['mail'] ?? [];
        $eventType = strtolower((string) ($payload['eventType'] ?? $payload['notificationType'] ?? 'unknown'));
        $sesMessageId = $message['messageId'] ?? null;
        $detail = $payload[$eventType] ?? $payload[ucfirst($eventType)] ?? [];
        $recipient = $this->recipient($eventType, $detail, $message);
        $providerEventId = hash('sha256', json_encode([
            $source->id,
            $providerMessageId ?: $this->canonicalPayload($payload),
        ], JSON_THROW_ON_ERROR));

        $event = DB::transaction(function () use ($source, $sesMessageId, $providerEventId, $eventType, $recipient, $detail, $payload, $message): EmailEvent {
            $email = $sesMessageId
                ? Email::query()->where('source_id', $source->id)->where('ses_message_id', $sesMessageId)->lockForUpdate()->first()
                : null;

            $event = EmailEvent::query()->createOrFirst(['provider_event_id' => $providerEventId], [
                'email_id' => $email?->id,
                'source_id' => $source->id,
                'event_type' => $eventType,
                'ses_message_id' => $sesMessageId,
                'recipient' => $recipient,
                'url' => $detail['link'] ?? null,
                'user_agent' => $detail['userAgent'] ?? null,
                'ip_address' => $detail['ipAddress'] ?? null,
                'payload' => $payload,
                'occurred_at' => $this->occurredAt($message, $detail),
            ]);

            if ($email && ($event->wasRecentlyCreated || $event->email_id === null)) {
                $event->forceFill(['email_id' => $email->id])->save();
                $this->applyEvent($email, $event);
            }

            return $event;
        });

        if ($event->email_id === null && $sesMessageId) {
            $email = Email::query()->where('source_id', $source->id)->where('ses_message_id', $sesMessageId)->first();

            if ($email) {
                $this->reconcile($email);

                return $event->refresh();
            }
        }

        if ($event->email) {
            EmailActivityUpdated::dispatch($event->email->fresh());
        }

        $this->webhookDeliveryService->dispatchFor($event);

        return $event;
    }

    public function reconcile(Email $email): void
    {
        if (! $email->ses_message_id) {
            return;
        }

        $events = DB::transaction(function () use ($email): Collection {
            $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();
            $events = EmailEvent::query()->where('source_id', $lockedEmail->source_id)
                ->where('ses_message_id', $lockedEmail->ses_message_id)
                ->whereNotNull('provider_event_id')
                ->where(fn ($query) => $query->whereNull('email_id')->orWhere('email_id', $lockedEmail->id))
                ->lockForUpdate()->get();

            foreach ($events as $event) {
                if ($event->email_id === null) {
                    $event->forceFill(['email_id' => $lockedEmail->id])->save();
                    $this->applyEvent($lockedEmail, $event);
                }
            }

            return $events;
        });

        foreach ($events as $event) {
            $this->webhookDeliveryService->dispatchFor($event);
        }
    }

    private function applyEvent(Email $email, EmailEvent $event): void
    {
        $nextStatus = $this->statusFor($event->event_type);

        if ($nextStatus !== null && $this->statusPriority($nextStatus) > $this->statusPriority($email->status)) {
            $email->forceFill(['status' => $nextStatus])->save();
        }

        $this->recordSuppression($email, $event->event_type, $event->payload, $event->recipient);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function canonicalPayload(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalPayload($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $detail
     */
    private function occurredAt(array $message, array $detail): CarbonImmutable
    {
        return CarbonImmutable::parse($detail['timestamp'] ?? $message['timestamp'] ?? now());
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $message
     */
    private function recipient(string $eventType, array $detail, array $message): ?string
    {
        return match ($eventType) {
            'bounce' => $detail['bouncedRecipients'][0]['emailAddress'] ?? null,
            'complaint' => $detail['complainedRecipients'][0]['emailAddress'] ?? null,
            'delivery' => $detail['recipients'][0] ?? null,
            default => $message['destination'][0] ?? null,
        };
    }

    private function statusFor(string $eventType): ?string
    {
        return match ($eventType) {
            'delivery' => 'delivered',
            'open' => 'opened',
            'click' => 'clicked',
            'bounce' => 'bounced',
            'complaint' => 'complained',
            'reject' => 'rejected',
            'deliverydelay' => 'delayed',
            'renderingfailure' => 'failed',
            'send' => 'sent',
            default => null,
        };
    }

    private function statusPriority(string $status): int
    {
        return match ($status) {
            'queued' => 0,
            'sending' => 1,
            'sent' => 2,
            'delayed' => 3,
            'delivered' => 4,
            'opened' => 5,
            'clicked' => 6,
            'failed' => 7,
            'rejected' => 8,
            'bounced' => 9,
            'complained' => 10,
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordSuppression(Email $email, string $eventType, array $payload, ?string $recipient): void
    {
        if (! $recipient) {
            return;
        }

        $reason = match ($eventType) {
            'complaint' => 'complaint',
            'bounce' => $this->isPermanentBounce($payload) ? 'hard_bounce' : null,
            default => null,
        };

        if (! $reason) {
            return;
        }

        Suppression::query()->updateOrCreate(
            [
                'project_id' => $email->project_id,
                'email' => $recipient,
            ],
            [
                'workspace_id' => $email->workspace_id,
                'source_id' => $email->source_id,
                'email_id' => $email->id,
                'reason' => $reason,
                'event_type' => $eventType,
                'expires_at' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isPermanentBounce(array $payload): bool
    {
        $bounce = $payload['bounce'] ?? $payload['Bounce'] ?? [];
        $type = strtolower((string) ($bounce['bounceType'] ?? ''));

        return in_array($type, ['permanent', 'undetermined'], true);
    }
}
