<?php

namespace App\Services;

use App\Models\EmailEvent;
use Carbon\CarbonImmutable;
use Throwable;

class EventEvidence
{
    /** @return array<string, mixed> */
    public function for(EmailEvent $event, ?string $origin = null): array
    {
        if ($event->public_event_data !== null) {
            return $event->public_event_data;
        }

        $legacy = $event->contract_version !== 2;
        $isSes = $origin === 'ses' || ($origin === null && $event->provider_event_id !== null);
        $payload = $event->payload ?? [];
        $detail = $this->providerDetail($event->event_type, $payload);
        [$recipients, $attribution] = $this->recipients($event->event_type, $detail, $payload);
        $url = $this->publicUrl($event->url);
        $details = $this->details($detail);
        $details['recipient_attribution'] = $attribution;

        if ($event->url !== null) {
            $details['url_redacted'] = $url !== $event->url;
        }

        if (! $legacy && ! $isSes) {
            foreach (['retryable', 'final'] as $key) {
                if (is_bool($payload[$key] ?? null)) {
                    $details[$key] = $payload[$key];
                }
            }

            if (is_string($payload['code'] ?? null) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $payload['code']) === 1) {
                $details['code'] = $payload['code'];
            }
        }

        $recipient = $event->recipient;

        if (! $legacy) {
            $recipient = count($recipients) === 1 ? $recipients[0] : null;
        }

        $attemptId = $event->submission_correlation_id ?? ($payload['attempt_id'] ?? $payload['mail']['tags'][SesV2Client::CORRELATION_TAG][0] ?? null);
        $occurredAt = $event->occurred_at->toIso8601String();

        if (! $legacy && $isSes) {
            $timestamp = $detail['timestamp'] ?? $payload['mail']['timestamp'] ?? null;

            if (is_string($timestamp)) {
                try {
                    $occurredAt = CarbonImmutable::parse($timestamp)->toISOString();
                } catch (Throwable) {
                    // Invalid historical provider timestamps cannot replace the stored occurrence.
                }
            }
        }

        return [
            'id' => 'evt_'.$event->id,
            'object' => 'email_event',
            'contract_version' => $legacy ? 1 : 2,
            'type' => $event->event_type,
            'created_at' => $occurredAt,
            'occurred_at' => $occurredAt,
            'recorded_at' => $event->created_at->toIso8601String(),
            'email_id' => $event->email?->public_id,
            'source_id' => $event->source_id,
            'sequence' => $event->email_sequence,
            'origin' => $legacy ? 'legacy' : ($isSes ? 'ses' : 'internal'),
            'attempt_id' => ! $legacy && is_string($attemptId) && preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/', $attemptId) === 1 ? $attemptId : null,
            'recipient' => $recipient,
            'recipients' => $recipients,
            'url' => $url,
            'user_agent' => $event->user_agent,
            'ip_address' => $event->ip_address,
            'details' => $details,
            'payload' => $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function providerDetail(string $type, array $payload): array
    {
        $key = match ($type) {
            'delivery_delay', 'deliverydelay' => 'deliveryDelay',
            'rendering_failure', 'renderingfailure', 'rendering failure' => 'failure',
            default => $type,
        };
        $detail = $payload[$key] ?? $payload[ucfirst($key)] ?? [];

        return is_array($detail) ? $detail : [];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $payload
     * @return array{array<int, string>, string}
     */
    private function recipients(string $type, array $detail, array $payload): array
    {
        $key = match ($type) {
            'bounce' => 'bouncedRecipients',
            'complaint' => 'complainedRecipients',
            'delivery' => 'recipients',
            'suppress' => 'recipients',
            'delivery_delay', 'deliverydelay' => 'delayedRecipients',
            default => null,
        };
        $values = $key === null ? ($payload['mail']['destination'] ?? []) : ($detail[$key] ?? []);

        if (! is_array($values)) {
            return [[], 'unknown'];
        }

        $recipients = [];

        foreach ($values as $value) {
            $address = is_array($value) ? ($value['emailAddress'] ?? null) : $value;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[] = $address;
            }
        }

        $recipients = array_values(array_unique($recipients));

        return [$recipients, $recipients === [] ? 'unknown' : ($key === null ? 'message' : 'recipient')];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function details(array $detail): array
    {
        $details = [];

        if (in_array($detail['transition'] ?? null, ['created', 'reactivated'], true)) {
            $details['transition'] = $detail['transition'];
        }

        foreach (['bounceType' => 'bounce_type', 'bounceSubType' => 'bounce_subtype',
            'complaintFeedbackType' => 'complaint_feedback_type', 'delayType' => 'delay_type',
            'expirationTime' => 'expiration_time', 'reportingMTA' => 'reporting_mta',
            'smtpResponse' => 'smtp_response', 'errorMessage' => 'error_message',
            'templateName' => 'template_name', 'reason' => 'reason', 'feedbackId' => 'feedback_id'] as $key => $name) {
            if (is_string($detail[$key] ?? null)) {
                $details[$name] = $this->safeText($detail[$key]);
            }
        }

        foreach (['processingTimeMillis' => 'processing_time_ms'] as $key => $name) {
            if (is_int($detail[$key] ?? null)) {
                $details[$name] = $detail[$key];
            }
        }

        foreach (['bouncedRecipients', 'complainedRecipients', 'delayedRecipients'] as $key) {
            if (! is_array($detail[$key] ?? null)) {
                continue;
            }

            $details['recipients'] = [];

            foreach ($detail[$key] as $recipient) {
                if (! is_array($recipient)) {
                    continue;
                }

                $entry = [];

                foreach (['emailAddress' => 'email', 'action' => 'action', 'status' => 'status', 'diagnosticCode' => 'diagnostic_code'] as $field => $name) {
                    if (is_string($recipient[$field] ?? null)) {
                        $entry[$name] = $this->safeText($recipient[$field]);
                    }
                }

                if ($entry !== []) {
                    $details['recipients'][] = $entry;
                }
            }
        }

        return $details;
    }

    private function safeText(string $value): string
    {
        return mb_substr(preg_replace_callback('~https?://[^\s<>"\x27]+~i', fn (array $match): string => $this->publicUrl($match[0]) ?? '[redacted URL]', $value), 0, 2000);
    }

    private function publicUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
