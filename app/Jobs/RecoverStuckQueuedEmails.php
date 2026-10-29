<?php

namespace App\Jobs;

use App\Models\Email;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RecoverStuckQueuedEmails implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(): void
    {
        Email::query()->select('id')
            ->where('status', 'queued')
            ->where('created_at', '<', now()->subMinutes(max(2, (int) config('larasend.queued_recovery.minimum_age_minutes', 2))))
            ->limit(max(1, (int) config('larasend.queued_recovery.batch_size', 500)))
            ->orderBy('created_at')->orderBy('id')
            ->get()->each(fn (Email $email) => SendQueuedEmail::dispatchAfterCommit($email->id));
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
