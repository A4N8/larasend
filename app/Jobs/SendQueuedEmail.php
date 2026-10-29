<?php

namespace App\Jobs;

use App\Events\EmailActivityUpdated;
use App\Exceptions\EmailNotSubmittedException;
use App\Models\Email;
use App\Models\EmailEvent;
use App\Services\Providers\EmailProviderFactory;
use App\Services\SesEventNormalizer;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public static function dispatchAfterCommit(int $emailId): void
    {
        DB::afterCommit(static function () use ($emailId): void {
            $job = new self($emailId);

            try {
                dispatch($job)->beforeCommit();
            } catch (Throwable $exception) {
                (new UniqueLock(app(Repository::class)))->release($job);

                throw $exception;
            }
        });
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
            if (Email::query()->whereKey($email->id)->where('status', 'queued')->update(['status' => 'sending']) !== 1) {
                return null;
            }

            return $email->events()->create([
                'source_id' => $email->source_id,
                'event_type' => 'submission_started',
                'payload' => ['message' => 'Submission is in progress. If sending persists after the worker stops, check provider delivery records before retrying manually.'],
                'occurred_at' => now(),
            ]);
        });

        if ($submission === null) {
            return;
        }

        try {
            $result = $provider->sendRawEmail($email->source, $mime, $envelope);
        } catch (Throwable $exception) {
            $definitelyNotSubmitted = $exception instanceof EmailNotSubmittedException
                || ($exception instanceof RequestException && $exception->response->clientError());

            if ($definitelyNotSubmitted) {
                Email::query()->whereKey($email->id)->where('status', 'sending')->update(['status' => 'queued']);
            }

            $submission->forceFill([
                'event_type' => $definitelyNotSubmitted ? 'submission_rejected' : 'delivery_uncertain',
                'payload' => [
                    'error' => $exception->getMessage(),
                    'message' => $definitelyNotSubmitted
                        ? 'No message was accepted by the provider. This email can be retried safely.'
                        : 'The provider may have accepted this email. Check provider delivery records before retrying manually.',
                ],
            ])->save();

            EmailActivityUpdated::dispatch($email->fresh());

            throw $exception;
        }

        DB::transaction(function () use ($email, $result, $submission): void {
            $lockedEmail = Email::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();
            $lockedEmail->forceFill([
                'status' => $lockedEmail->status === 'sending' ? 'sent' : $lockedEmail->status,
                'ses_message_id' => $result['message_id'],
                'sent_at' => now(),
            ])->save();

            $submission->forceFill([
                'event_type' => 'send',
                'ses_message_id' => $result['message_id'],
                'payload' => $result['response'],
                'occurred_at' => now(),
            ])->save();
        });

        app(SesEventNormalizer::class)->reconcile($email->fresh());
        EmailActivityUpdated::dispatch($email->fresh());
    }

    /**
     * The typed recipient groups matter: Symfony Mime strips the Bcc header
     * from the stored message, so every provider must receive recipients out
     * of band — SES as an explicit Destination, SMTP in the envelope.
     *
     * @return array{from: string, recipients: array<int, string>, to: array<int, string>, cc: array<int, string>, bcc: array<int, string>}
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

        if (Email::query()->whereKey($email->id)->where('status', 'queued')->update(['status' => 'failed']) !== 1) {
            return;
        }

        $email->events()->create([
            'source_id' => $email->source_id,
            'event_type' => 'failed',
            'payload' => ['error' => $exception->getMessage()],
            'occurred_at' => now(),
        ]);

        EmailActivityUpdated::dispatch($email->fresh());
    }
}
