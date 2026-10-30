<?php

namespace App\Services;

use App\Events\EmailActivityUpdated;
use App\Jobs\ReconcileSesEvents;
use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Source;
use App\Models\Suppression;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SesEventNormalizer
{
    public const RECONCILIATION_BATCH_SIZE = 100;

    private const PROVIDER_EVENT_TYPES = ['send', 'delivery', 'bounce', 'complaint', 'open', 'click',
        'reject', 'delivery_delay', 'deliverydelay', 'rendering_failure', 'renderingfailure', 'rendering failure'];

    private EventPublisher $publisher;

    public function __construct(WebhookDeliveryService $webhookDeliveryService, ?EventPublisher $publisher = null)
    {
        $this->publisher = $publisher ?? new EventPublisher(app(EventEvidence::class), $webhookDeliveryService);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Source $source, array $payload, ?string $providerMessageId = null): ?EmailEvent
    {
        $message = $payload['mail'] ?? [];
        $eventType = match (strtolower((string) ($payload['eventType'] ?? $payload['notificationType'] ?? 'unknown'))) {
            'deliverydelay', 'delivery_delay' => 'delivery_delay',
            'rendering failure', 'renderingfailure', 'rendering_failure' => 'rendering_failure',
            default => strtolower((string) ($payload['eventType'] ?? $payload['notificationType'] ?? 'unknown')),
        };
        $sesMessageId = $message['messageId'] ?? null;
        $detailKey = match ($eventType) {
            'delivery_delay' => 'deliveryDelay',
            'rendering_failure' => 'failure',
            default => $eventType,
        };
        $detail = $payload[$detailKey] ?? $payload[ucfirst($detailKey)] ?? [];
        $recipient = $this->recipient($eventType, $detail, $message);
        $providerEventId = hash('sha256', json_encode([
            $source->id,
            $providerMessageId ?: $this->canonicalPayload($payload),
        ], JSON_THROW_ON_ERROR));

        $event = EmailEvent::query()->createOrFirst(['provider_event_id' => $providerEventId], [
            'email_id' => null,
            'source_id' => $source->id,
            'contract_version' => 2,
            'event_type' => $eventType,
            'ses_message_id' => $sesMessageId,
            'recipient' => $recipient,
            'url' => $detail['link'] ?? null,
            'user_agent' => $detail['userAgent'] ?? null,
            'ip_address' => $detail['ipAddress'] ?? null,
            'payload' => $payload,
            'occurred_at' => $this->occurredAt($message, $detail),
        ]);
        $event = $this->associate($source, $event, true);

        if ($event->email_id === null) {
            DB::afterCommit(fn () => $this->associate($source, $event, true));
        }

        if ($event->email_id !== null) {
            EmailActivityUpdated::dispatch($event->email->fresh());
        }

        return $event;
    }

    public function reconcile(Email $email, int $afterEventId = 0, ?int $throughEventId = null): void
    {
        $replayLinked = $throughEventId === null;

        if ($email->ses_message_id) {
            $query = EmailEvent::query()->where('source_id', $email->source_id)
                ->where('ses_message_id', $email->ses_message_id)
                ->whereIn('event_type', self::PROVIDER_EVENT_TYPES)
                ->whereNull('email_id');
            $throughEventId ??= (clone $query)->max('id');
            $orphans = $query->where('id', '>', $afterEventId)->where('id', '<=', $throughEventId ?? 0)
                ->orderBy('id')->limit(self::RECONCILIATION_BATCH_SIZE)->get();

            foreach ($orphans as $event) {
                $this->associate($email->source, $event);
            }

            if ($orphans->count() === self::RECONCILIATION_BATCH_SIZE && $orphans->last()->id < $throughEventId) {
                (new ReconcileSesEvents($email->id, $email->source_id, $email->ses_message_id,
                    $orphans->last()->id, $throughEventId))->dispatchAfterCommit();
            }
        }

        if (! $replayLinked) {
            return;
        }

        $events = $email->events()->where('source_id', $email->source_id)->whereIn('event_type', self::PROVIDER_EVENT_TYPES)
            ->latest('id')->limit(self::RECONCILIATION_BATCH_SIZE)->get();

        foreach ($events as $event) {
            $this->associate($email->source, $event);
        }
    }

    private function associate(Source $source, EmailEvent $event, bool $scheduleBindingRecovery = false): EmailEvent
    {
        return DB::transaction(function () use ($source, $event, $scheduleBindingRecovery): EmailEvent {
            $email = $this->resolveEmail($source, $event);

            if ($email === null) {
                return $event;
            }

            $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();
            $event = EmailEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($lockedEmail->source_id !== $source->id
                || ($lockedEmail->ses_message_id !== null && $lockedEmail->ses_message_id !== $event->ses_message_id)
                || $this->resolveEmail($source, $event)?->id !== $lockedEmail->id
                || ($event->email_id !== null && $event->email_id !== $lockedEmail->id)) {
                return $event;
            }

            $newAssociation = $event->email_id === null;
            $newBinding = $lockedEmail->ses_message_id === null;

            if ($newBinding) {
                $lockedEmail->update(['ses_message_id' => $event->ses_message_id]);
            }

            $event = $this->publisher->publish($event, $lockedEmail, 'ses');

            if ($newAssociation) {
                $this->applyEvent($lockedEmail, $event);
            }

            if ($scheduleBindingRecovery && ($newBinding || (! $newAssociation && isset($event->payload['mail']['tags'][SesV2Client::CORRELATION_TAG])))) {
                DB::afterCommit(fn () => $this->recoverBindingOrphans($source, $event));
            }

            return $event;
        });
    }

    private function recoverBindingOrphans(Source $source, EmailEvent $event): void
    {
        $email = $this->resolveEmail($source, $event);

        if ($email === null || $event->email_id !== $email->id) {
            return;
        }

        $throughEventId = EmailEvent::query()->where('source_id', $source->id)
            ->where('ses_message_id', $event->ses_message_id)->whereNull('email_id')
            ->whereIn('event_type', self::PROVIDER_EVENT_TYPES)->max('id');

        if ($throughEventId !== null) {
            (new ReconcileSesEvents($email->id, $source->id, $event->ses_message_id, 0, $throughEventId))->dispatchAfterCommit();
        }
    }

    private function resolveEmail(Source $source, EmailEvent $event): ?Email
    {
        if ($event->source_id !== $source->id || ! is_string($event->ses_message_id) || $event->ses_message_id === ''
            || ($event->payload['mail']['messageId'] ?? null) !== $event->ses_message_id
            || ($event->provider_event_id === null && ! in_array($event->event_type, self::PROVIDER_EVENT_TYPES, true))) {
            return null;
        }

        $matches = Email::query()->where('source_id', $source->id)->where('ses_message_id', $event->ses_message_id)->limit(2)->get();

        if ($matches->count() > 1) {
            return null;
        }

        $tags = $event->payload['mail']['tags'] ?? [];

        if (! is_array($tags)) {
            return null;
        }

        if (! array_key_exists(SesV2Client::CORRELATION_TAG, $tags)) {
            return $matches->first();
        }

        $values = $tags[SesV2Client::CORRELATION_TAG];

        if (! is_array($values) || count($values) !== 1 || ! is_string($values[0] ?? null)
            || preg_match('/\A[a-zA-Z0-9]{48}\z/', $values[0]) !== 1) {
            return null;
        }

        $attempt = EmailEvent::query()->where('source_id', $source->id)
            ->where('submission_correlation_id', $values[0])->where('event_type', 'submission_started')->first();
        $email = $attempt?->email_id === null ? null : Email::query()->where('source_id', $source->id)->whereKey($attempt->email_id)->first();

        if ($email === null || ($matches->isNotEmpty() && $matches->first()->id !== $email->id)
            || ($email->ses_message_id !== null && $email->ses_message_id !== $event->ses_message_id)) {
            return null;
        }

        return $email;
    }

    private function applyEvent(Email $email, EmailEvent $event): void
    {
        $nextStatus = $this->statusFor($event->event_type);

        if ($nextStatus !== null && $this->statusPriority($nextStatus) > $this->statusPriority($email->status)) {
            $email->forceFill(['status' => $nextStatus])->save();
        }

        $this->recordSuppression($email, $event);
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
        $recipients = match ($eventType) {
            'bounce' => array_column($detail['bouncedRecipients'] ?? [], 'emailAddress'),
            'complaint' => array_column($detail['complainedRecipients'] ?? [], 'emailAddress'),
            'delivery_delay' => array_column($detail['delayedRecipients'] ?? [], 'emailAddress'),
            'delivery' => $detail['recipients'] ?? [],
            default => $message['destination'] ?? [],
        };

        return count($recipients) === 1 && is_string($recipients[0] ?? null) ? $recipients[0] : null;
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
            'delivery_delay', 'deliverydelay' => 'delayed',
            'rendering_failure', 'renderingfailure', 'rendering failure' => 'failed',
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

    private function recordSuppression(Email $email, EmailEvent $event): void
    {
        $eventType = $event->event_type;
        $payload = $event->payload;
        $recipient = $event->recipient;
        $reason = match ($eventType) {
            'complaint' => 'complaint',
            'bounce' => $this->isPermanentBounce($payload) ? 'hard_bounce' : null,
            default => null,
        };

        if (! $reason) {
            return;
        }

        $detail = $payload[$eventType] ?? $payload[ucfirst($eventType)] ?? [];
        $recipientKey = $eventType === 'complaint' ? 'complainedRecipients' : 'bouncedRecipients';
        $recipients = collect($detail[$recipientKey] ?? [['emailAddress' => $recipient]])
            ->pluck('emailAddress')
            ->filter(fn (mixed $address): bool => is_string($address) && Suppression::normalizeEmail($address) !== '')
            ->map(fn (string $address): string => Suppression::normalizeEmail($address))
            ->uniqueStrict()
            ->sort(SORT_STRING);

        foreach ($recipients as $address) {
            $values = [
                'workspace_id' => $email->workspace_id,
                'source_id' => $email->source_id,
                'email_id' => $email->id,
                'reason' => $reason,
                'event_type' => $eventType,
                'expires_at' => null,
            ];
            $candidate = Suppression::query()->where('project_id', $email->project_id)
                ->whereNormalizedEmail($address)
                ->firstOrCreate([], ['project_id' => $email->project_id, 'email' => $address, ...$values]);
            $created = $candidate->wasRecentlyCreated;
            $suppression = Suppression::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $isActive = $suppression->expires_at === null || $suppression->expires_at->isFuture();
            $isOwned = $suppression->source_id === $email->source_id
                && $suppression->email_id !== null
                && in_array($suppression->reason, ['hard_bounce', 'complaint'], true)
                && in_array($suppression->event_type, ['bounce', 'complaint'], true);

            if ($isActive && ! $isOwned) {
                continue;
            }

            if ($isActive && $suppression->reason === 'complaint' && $reason !== 'complaint') {
                continue;
            }

            $suppression->update($values);

            if ($created || ! $isActive) {
                $this->publisher->record($email, [
                    'event_type' => 'suppress',
                    'recipient' => $address,
                    'payload' => ['attempt_id' => $event->public_event_data['attempt_id'] ?? null,
                        'suppress' => ['reason' => $reason, 'recipients' => [$address],
                            'transition' => $created ? 'created' : 'reactivated']],
                    'occurred_at' => now(),
                ]);
            }
        }
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
