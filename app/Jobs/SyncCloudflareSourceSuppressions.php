<?php

namespace App\Jobs;

use App\Enums\SourceProvider;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Services\CloudflareApiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Mirrors one Cloudflare account's stable suppression snapshot into Larasend.
 */
#[UniqueFor(3900)]
class SyncCloudflareSourceSuppressions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const SNAPSHOT_BUDGET_SECONDS = 50;

    public const WORK_BUDGET_SECONDS = 70;

    public int $tries = 3;

    public int $timeout = 75;

    public function __construct(public int $sourceId) {}

    public function handle(CloudflareApiClient $cloudflare): void
    {
        $deadline = $this->monotonicTime() + self::WORK_BUDGET_SECONDS;
        $source = Source::query()
            ->whereKey($this->sourceId)
            ->where('provider', SourceProvider::Cloudflare)
            ->whereNotNull('cloudflare_api_token')
            ->whereNotNull('cloudflare_account_id')
            ->with('project')
            ->first();

        if ($source?->project === null) {
            return;
        }

        $project = $source->project;
        $suppressions = $cloudflare->listStableSuppressions(
            $source,
            min(self::SNAPSHOT_BUDGET_SECONDS, $this->remainingWorkBudget($deadline)),
        );
        DB::transaction(function () use ($project, $source, $suppressions, $deadline): void {
            $syncedEmails = [];

            foreach ($suppressions as $suppression) {
                $this->ensureWithinWorkBudget($deadline);
                $email = Suppression::normalizeEmail($suppression['email']);

                if ($email === '') {
                    continue;
                }

                $values = [
                    'workspace_id' => $project->workspace_id,
                    'source_id' => $source->id,
                    'email_id' => null,
                    'reason' => $this->mapReason($suppression['reason']),
                    'event_type' => 'provider_sync',
                    'expires_at' => $suppression['expires_at'] ? Carbon::parse($suppression['expires_at']) : null,
                    'provider_metadata' => ['id' => $suppression['id'], 'scope' => $suppression['scope'], 'read_only' => $suppression['read_only']],
                ];

                $candidate = $this->suppressionForEmail($project, $email, $values);

                $isOwned = DB::transaction(function () use ($project, $source, $candidate, $values): bool {
                    $existing = $project->suppressions()
                        ->whereKey($candidate->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $isCloudflareOwned = $existing->source_id === $source->id
                        && $existing->event_type === 'provider_sync'
                        && data_get($existing->provider_metadata, 'scope.type') === 'account';
                    $isActive = $existing->expires_at === null
                        || $existing->expires_at->isFuture();

                    if (! $isCloudflareOwned && $isActive) {
                        return false;
                    }

                    $existing->update($values);

                    return true;
                });

                if ($isOwned) {
                    $syncedEmails[] = $email;
                }
            }

            $this->ensureWithinWorkBudget($deadline);
            $providerSyncSuppressions = $project->suppressions()
                ->where('source_id', $source->id)
                ->where('event_type', 'provider_sync')
                ->where('provider_metadata->scope->type', 'account')
                ->active();

            if ($syncedEmails !== []) {
                $providerSyncSuppressions->whereNormalizedEmailNotIn($syncedEmails);
            }

            $providerSyncSuppressions->update(['expires_at' => now()]);
            $this->ensureWithinWorkBudget($deadline);
        });
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }

    private function ensureWithinWorkBudget(float $deadline): void
    {
        $this->remainingWorkBudget($deadline);
    }

    private function remainingWorkBudget(float $deadline): float
    {
        $remaining = $deadline - $this->monotonicTime();

        if ($remaining <= 0) {
            throw new RuntimeException('Cloudflare suppression source sync exceeded its work budget. No destructive changes were made.');
        }

        return $remaining;
    }

    protected function monotonicTime(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function suppressionForEmail(Project $project, string $email, array $values): Suppression
    {
        return $project->suppressions()
            ->whereNormalizedEmail($email)
            ->firstOrCreate([], ['email' => $email, ...$values]);
    }

    private function mapReason(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'complaint') || str_contains($reason, 'spam') => 'complaint',
            in_array($reason, ['manual', 'policy', 'soft_bounce'], true) => $reason,
            default => 'hard_bounce',
        };
    }
}
