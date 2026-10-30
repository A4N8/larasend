<?php

namespace App\Services;

use App\Models\Email;
use App\Models\EmailEvent;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class EventPublisher
{
    public function __construct(private EventEvidence $evidence, private WebhookDeliveryService $webhooks) {}

    /** @param array<string, mixed> $attributes */
    public function record(Email $email, array $attributes): EmailEvent
    {
        return DB::transaction(function () use ($email, $attributes): EmailEvent {
            $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();
            $event = $lockedEmail->events()->create([
                ...$attributes,
                'source_id' => $lockedEmail->source_id,
                'contract_version' => 2,
                'public_event_data' => null,
                'email_sequence' => null,
            ]);

            return $this->publish($event, $lockedEmail);
        });
    }

    public function publish(EmailEvent $event, Email $email): EmailEvent
    {
        return DB::transaction(function () use ($event, $email): EmailEvent {
            $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();
            $lockedEvent = EmailEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($lockedEvent->source_id !== $lockedEmail->source_id
                || ($lockedEvent->email_id !== null && $lockedEvent->email_id !== $lockedEmail->id)) {
                throw new LogicException('Email event ownership does not match the email.');
            }

            $isLegacy = $lockedEvent->email_id !== null && $lockedEvent->contract_version === null;

            if (! $isLegacy && $lockedEvent->public_event_data === null) {
                $lockedEmail->increment('event_sequence');
                $lockedEvent->forceFill([
                    'email_id' => $lockedEmail->id,
                    'email_sequence' => $lockedEmail->event_sequence,
                    'contract_version' => 2,
                ]);
                $lockedEvent->setRelation('email', $lockedEmail);
                $lockedEvent->public_event_data = $this->evidence->for($lockedEvent);
                $lockedEvent->save();
            }

            DB::afterCommit(function () use ($lockedEvent): void {
                try {
                    $this->webhooks->dispatchFor($lockedEvent);
                } catch (Throwable $exception) {
                    try {
                        report($exception);
                    } catch (Throwable) {
                        // A reporting failure cannot invalidate committed event evidence.
                    }
                }
            });

            return $lockedEvent;
        });
    }
}
