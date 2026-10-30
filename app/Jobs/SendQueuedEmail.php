<?php

namespace App\Jobs;

use App\Events\EmailActivityUpdated;
use App\Exceptions\EmailNotSubmittedException;
use App\Models\Email;
use App\Models\EmailEvent;
use App\Services\EventPublisher;
use App\Services\Providers\EmailProviderFactory;
use App\Services\SesEventNormalizer;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendQueuedEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function __construct(public int $emailId) {}

    public function uniqueId(): string
    {
        return (string) $this->emailId;
    }

    public static function dispatchAfterCommit(int $emailId, bool $reportFailure = false): void
    {
        DB::afterCommit(static function () use ($emailId, $reportFailure): void {
            $job = new self($emailId);

            try {
                $connection = Queue::connection($job->connection);
            } catch (Throwable $exception) {
                if (! $reportFailure) {
                    throw $exception;
                }

                self::reportDispatchFailure($exception);

                return;
            }

            try {
                dispatch($job)->beforeCommit();
            } catch (Throwable $exception) {
                try {
                    (new UniqueLock(app(Repository::class)))->release($job);
                } catch (Throwable $releaseException) {
                    self::reportDispatchFailure($releaseException);
                }

                if ($reportFailure && ! $connection instanceof SyncQueue) {
                    self::reportDispatchFailure($exception);

                    return;
                }

                throw $exception;
            }
        });
    }

    private static function reportDispatchFailure(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Reporting failures must not turn an accepted durable email into a rejected request.
        }
    }

    public function handle(EmailProviderFactory $providers): void
    {
        $email = Email::query()->with(['project', 'source', 'recipients'])->findOrFail($this->emailId);

        if ($email->status !== 'queued') {
            app(SesEventNormalizer::class)->reconcile($email);

            return;
        }

        if ($email->project->archived_at !== null) {
            $this->failed(new RuntimeException('Project was archived before this message could be sent.'));

            return;
        }

        $mime = $email->mime_path
            ? Storage::disk($email->mime_disk)->get($email->mime_path)
            : null;

        if (! is_string($mime) || $mime === '') {
            throw new RuntimeException("Stored MIME content is missing for {$email->public_id}.");
        }

        $provider = $providers->forSource($email->source);
        $envelope = $this->envelope($email);

        $submission = DB::transaction(function () use ($email): ?EmailEvent {
            $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();

            if ($lockedEmail->status !== 'queued') {
                return null;
            }

            $lockedEmail->update(['status' => 'sending']);

            return app(EventPublisher::class)->record($lockedEmail, [
                'event_type' => 'submission_started',
                'submission_correlation_id' => Str::random(48),
                'payload' => ['code' => 'submission_started'],
                'occurred_at' => now(),
            ]);
        });

        if ($submission === null) {
            return;
        }

        $envelope['submission_correlation_id'] = $submission->submission_correlation_id;

        try {
            $result = $provider->sendRawEmail($email->source, $mime, $envelope);
        } catch (Throwable $exception) {
            $definitelyNotSubmitted = $exception instanceof EmailNotSubmittedException
                || ($exception instanceof RequestException && $exception->response->clientError());

            DB::transaction(function () use ($email, $submission, $exception, $definitelyNotSubmitted): void {
                $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();

                if ($definitelyNotSubmitted && $lockedEmail->status === 'sending') {
                    $lockedEmail->update(['status' => 'queued']);
                }

                app(EventPublisher::class)->record($lockedEmail, [
                    'event_type' => $definitelyNotSubmitted ? 'submission_rejected' : 'delivery_uncertain',
                    'payload' => ['attempt_id' => $submission->submission_correlation_id,
                        'code' => $definitelyNotSubmitted ? 'provider_rejected' : 'provider_response_unknown',
                        'retryable' => $definitelyNotSubmitted, 'error' => $exception->getMessage()],
                    'occurred_at' => now(),
                ]);
            });

            EmailActivityUpdated::dispatch($email->fresh());

            throw $exception;
        }

        DB::transaction(function () use ($email, $result, $submission): void {
            $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();
            $lockedEmail->forceFill([
                'status' => $lockedEmail->status === 'sending' ? 'sent' : $lockedEmail->status,
                'ses_message_id' => $lockedEmail->ses_message_id ?? $result['message_id'],
                'sent_at' => now(),
            ])->save();

            app(EventPublisher::class)->record($lockedEmail, [
                'event_type' => 'submission_accepted',
                'ses_message_id' => $result['message_id'],
                'payload' => ['attempt_id' => $submission->submission_correlation_id,
                    'code' => 'provider_accepted', 'response' => $result['response']],
                'occurred_at' => now(),
            ]);
        });

        app(SesEventNormalizer::class)->reconcile($email->fresh());
        EmailActivityUpdated::dispatch($email->fresh());
    }

    /**
     * The typed recipient groups matter: Symfony Mime strips the Bcc header
     * from the stored message, so every provider must receive recipients out
     * of band — SES as an explicit Destination, SMTP in the envelope.
     *
     * @return array{from: string, recipients: array<int, string>, to: array<int, string>, cc: array<int, string>, bcc: array<int, string>, submission_correlation_id?: string}
     */
    private function envelope(Email $email): array
    {
        $grouped = $email->recipients->groupBy('type');

        return [
            'from' => $email->from_email,
            'recipients' => $email->recipients->pluck('email')->all(),
            'to' => $grouped->get('to', collect())->pluck('email')->all(),
            'cc' => $grouped->get('cc', collect())->pluck('email')->all(),
            'bcc' => $grouped->get('bcc', collect())->pluck('email')->all(),
        ];
    }

    public function failed(Throwable $exception): void
    {
        $email = Email::query()->find($this->emailId);

        if (! $email) {
            return;
        }

        DB::transaction(function () use ($email, $exception): void {
            $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();

            if ($lockedEmail->status !== 'queued') {
                return;
            }

            $lockedEmail->update(['status' => 'failed']);
            $attempt = $lockedEmail->events()->where('event_type', 'submission_started')->latest('id')->value('submission_correlation_id');
            app(EventPublisher::class)->record($lockedEmail, [
                'event_type' => 'failed',
                'payload' => ['attempt_id' => $attempt, 'code' => 'send_failed', 'final' => true,
                    'retryable' => false, 'error' => $exception->getMessage()],
                'occurred_at' => now(),
            ]);
        });

        EmailActivityUpdated::dispatch($email->fresh());
    }
}
