<?php

namespace App\Jobs;

use App\Models\Email;
use App\Services\SesEventNormalizer;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileSesEvents implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public int $emailId,
        public int $sourceId,
        public string $sesMessageId,
        public int $afterEventId,
        public int $throughEventId,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->emailId, $this->sourceId, hash('sha256', $this->sesMessageId), $this->afterEventId, $this->throughEventId]);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function dispatchAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            try {
                dispatch($this)->beforeCommit();
            } catch (Throwable $exception) {
                try {
                    (new UniqueLock(app(Repository::class)))->release($this);
                } catch (Throwable) {
                    // Preserve the enqueue error so the existing job or SNS notification can retry.
                }

                throw $exception;
            }
        });
    }

    public function handle(SesEventNormalizer $normalizer): void
    {
        $email = Email::query()->find($this->emailId);

        if ($email === null || $email->source_id !== $this->sourceId || $email->ses_message_id !== $this->sesMessageId
            || $this->afterEventId >= $this->throughEventId) {
            return;
        }

        $normalizer->reconcile($email, $this->afterEventId, $this->throughEventId);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
