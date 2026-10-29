<?php

namespace App\Services;

use App\Enums\SourceProvider;
use App\Exceptions\SuppressionProviderUnavailableException;
use App\Exceptions\SuppressionRemovalException;
use App\Models\Suppression;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SuppressionRemovalService
{
    private const CLOUDFLARE_SNAPSHOT_BUDGET_SECONDS = 10;

    public function __construct(
        private SesV2Client $ses,
        private CloudflareApiClient $cloudflare,
    ) {}

    public function remove(Suppression $suppression): void
    {
        DB::transaction(function () use ($suppression): void {
            $lockedSuppression = Suppression::query()->whereKey($suppression->id)->lockForUpdate()->firstOrFail();

            if ($lockedSuppression->source_id !== $suppression->source_id || $lockedSuppression->project_id !== $suppression->project_id) {
                throw new SuppressionRemovalException('This suppression changed after it was selected. Refresh the page and retry. The local blocker was preserved.');
            }

            $this->removeLocked($lockedSuppression);
        });
    }

    private function removeLocked(Suppression $suppression): void
    {
        $source = $suppression->source;

        if ($source === null) {
            $suppression->delete();

            return;
        }

        if ($source->provider === SourceProvider::Ses) {
            try {
                $this->ses->deleteSuppressedDestination($source, Suppression::normalizeEmail($suppression->email));
            } catch (Throwable $exception) {
                report($exception);

                if ($exception instanceof RequestException && $exception->response->status() === 403) {
                    throw new SuppressionProviderUnavailableException(
                        'Amazon SES denied suppression removal. Grant ses:DeleteSuppressedDestination to this source’s IAM principal, then retry. The local blocker was preserved.',
                        previous: $exception,
                    );
                }

                throw new SuppressionProviderUnavailableException(
                    'Amazon SES could not remove this address right now. The local blocker was preserved. Try again later.',
                    previous: $exception,
                );
            }

            $suppression->delete();

            return;
        }

        if (data_get($suppression->provider_metadata, 'scope.type') !== 'account') {
            throw new SuppressionRemovalException('This Cloudflare suppression has an unknown or sending-domain scope. Provider-scope verification is required before local removal; the blocker was preserved. Review its scope in Cloudflare first.');
        }

        try {
            $upstreamSuppressions = $this->cloudflare->listStableSuppressions(
                $source,
                self::CLOUDFLARE_SNAPSHOT_BUDGET_SECONDS,
            );
        } catch (Throwable $exception) {
            report($exception);

            throw new SuppressionProviderUnavailableException(
                'Cloudflare could not verify this address right now. The local blocker was preserved. Try again later.',
                previous: $exception,
            );
        }

        $email = Suppression::normalizeEmail($suppression->email);
        $upstream = collect($upstreamSuppressions)
            ->first(
                fn (array $upstream): bool => Suppression::normalizeEmail($upstream['email']) === $email,
            );

        if ($upstream !== null) {
            if ($upstream['read_only']) {
                throw new SuppressionRemovalException('Cloudflare marks this account suppression as read-only. Resolve it with Cloudflare before retrying; the local blocker was preserved.');
            }

            throw new SuppressionRemovalException(
                'Cloudflare still lists this account suppression upstream. Remove it in Cloudflare first, then retry. The local blocker was preserved.',
            );
        }

        $suppression->delete();
    }
}
