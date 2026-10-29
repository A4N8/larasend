<?php

namespace App\Jobs;

use App\Enums\SourceProvider;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Throwable;

/**
 * Dispatches a bounded page of independent Cloudflare suppression syncs.
 */
#[UniqueFor(3900)]
class SyncCloudflareSuppressions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const BATCH_SIZE = 25;

    public int $timeout = 15;

    public function __construct(public ?int $afterSourceId = null) {}

    public function handle(): void
    {
        $sourceIds = Source::query()
            ->where('provider', SourceProvider::Cloudflare)
            ->whereNotNull('cloudflare_api_token')
            ->whereNotNull('cloudflare_account_id')
            ->when($this->afterSourceId !== null, fn ($query) => $query->where('id', '>', $this->afterSourceId))
            ->orderBy('id')
            ->limit(self::BATCH_SIZE + 1)
            ->pluck('id');

        $sourceIds
            ->take(self::BATCH_SIZE)
            ->each(fn (int $sourceId) => SyncCloudflareSourceSuppressions::dispatch($sourceId)
                ->onConnection($this->connection)->onQueue($this->queue));

        if ($sourceIds->count() > self::BATCH_SIZE) {
            self::dispatch((int) $sourceIds[self::BATCH_SIZE - 1])
                ->onConnection($this->connection)->onQueue($this->queue);
        }
    }

    public function uniqueId(): string
    {
        return $this->afterSourceId === null
            ? 'cloudflare-suppressions:root'
            : "cloudflare-suppressions:after:{$this->afterSourceId}";
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
