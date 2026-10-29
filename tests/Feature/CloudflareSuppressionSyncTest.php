<?php

use App\Jobs\SyncCloudflareSourceSuppressions;
use App\Jobs\SyncCloudflareSuppressions;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CloudflareApiClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function cloudflareSuppressionSource(string $slug, string $accountId): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => $slug, 'slug' => $slug]);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => $slug, 'slug' => $slug]);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => "token-{$slug}",
        'cloudflare_account_id' => $accountId,
        'default_from_email' => 'receipts@example.com',
        'webhook_token' => 'token-'.str()->random(8),
    ]);

    return [$project, $source];
}

function runCloudflareSuppressionSourceSync(Source $source, ?CloudflareApiClient $cloudflare = null): void
{
    (new SyncCloudflareSourceSuppressions($source->id))
        ->handle($cloudflare ?? app(CloudflareApiClient::class));
}

function cloudflareSuppressionPageEnvelope(array $body, ?string $nextCursor = null): array
{
    $rows = array_map(fn (mixed $row): mixed => is_array($row)
        ? ['read_only' => false, 'scope' => ['type' => 'account'], ...$row]
        : $row, $body['result']);

    return [...$body, 'result' => $rows, 'result_info' => ['next_cursor' => $nextCursor, 'count' => count($rows)]];
}

function cloudflareSuppressionDataPage(int $page, ?string $nextCursor): array
{
    return cloudflareSuppressionPageEnvelope(['success' => true, 'result' => collect(range(1, 100))
        ->map(fn (int $index): array => ['id' => "suppression-{$page}-{$index}", 'email' => "recipient-{$page}-{$index}@example.com",
            'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null])->all()], $nextCursor);
}

it('pulls cloudflare suppressions into the project with mapped reasons', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-sync', 'acc-sync');

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-sync/email/sending/suppressions*' => Http::sequence()
            ->push(cloudflareSuppressionPageEnvelope([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'Bounced@Example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                    ['id' => 'sup-2', 'email' => 'complainer@example.com', 'reason' => 'spam_complaint', 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addMonth()->toIso8601String()],
                ],
            ], 'legacy-next'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]))
            ->push(cloudflareSuppressionPageEnvelope([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'Bounced@Example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                    ['id' => 'sup-2', 'email' => 'complainer@example.com', 'reason' => 'spam_complaint', 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addMonth()->toIso8601String()],
                ],
            ], 'legacy-next'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []])),
    ]);

    runCloudflareSuppressionSourceSync($source);

    $bounced = Suppression::query()->where('email', 'bounced@example.com')->firstOrFail();
    $complained = Suppression::query()->where('email', 'complainer@example.com')->firstOrFail();

    expect($bounced)
        ->project_id->toBe($project->id)
        ->workspace_id->toBe($project->workspace_id)
        ->source_id->toBe($source->id)
        ->reason->toBe('hard_bounce')
        ->event_type->toBe('provider_sync')
        ->expires_at->toBeNull()
        ->and($complained->reason)->toBe('complaint')
        ->and($complained->expires_at)->not->toBeNull();
});

it('is idempotent across repeated sync runs', function () {
    [, $source] = cloudflareSuppressionSource('cf-idem', 'acc-idem');

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-idem/email/sending/suppressions*' => Http::sequence()
            ->push(cloudflareSuppressionPageEnvelope([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'repeat@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ], 'legacy-next'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]))
            ->push(cloudflareSuppressionPageEnvelope([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'repeat@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ], 'legacy-next'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]))
            ->push(cloudflareSuppressionPageEnvelope([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'repeat@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ], 'legacy-next'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]))
            ->push(cloudflareSuppressionPageEnvelope([
                'success' => true,
                'result' => [
                    ['id' => 'sup-1', 'email' => 'repeat@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ], 'legacy-next'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []])),
    ]);

    runCloudflareSuppressionSourceSync($source);
    runCloudflareSuppressionSourceSync($source);

    expect(Suppression::query()->where('email', 'repeat@example.com')->count())->toBe(1);
});

it('expires missing provider sync suppressions while retaining history only for the fetched source', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-prune', 'acc-prune');
    $otherSource = Source::create([
        'project_id' => $project->id,
        'name' => 'Historical source',
        'environment' => 'staging',
        'provider' => 'ses',
        'webhook_token' => 'token-'.str()->random(8),
    ]);

    $missingUpstream = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'missing-upstream@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
        'provider_metadata' => ['id' => 'missing-upstream', 'scope' => ['type' => 'account'], 'read_only' => false],
    ]);
    $otherSourceSuppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $otherSource->id,
        'email' => 'other-source@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
    ]);
    $localSuppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'local-event@example.com',
        'reason' => 'complaint',
        'event_type' => 'complaint',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-prune/email/sending/suppressions*' => Http::response(cloudflareSuppressionPageEnvelope([
            'success' => true,
            'result' => [],
        ])),
    ]);

    runCloudflareSuppressionSourceSync($source);

    expect($missingUpstream->fresh())->not->toBeNull()
        ->and($missingUpstream->fresh()->expires_at)->not->toBeNull()
        ->and($missingUpstream->fresh()->expires_at->isPast())->toBeTrue()
        ->and($otherSourceSuppression->fresh())->not->toBeNull()
        ->and($localSuppression->fresh())->not->toBeNull();
});

it('preserves provider sync suppressions when the Cloudflare fetch fails', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-preserve', 'acc-preserve');

    $suppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'still-suppressed@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-preserve/email/sending/suppressions*' => Http::response([
            'success' => false,
            'errors' => [['code' => 10001, 'message' => 'invalid token']],
        ], 401),
    ]);

    expect(fn () => runCloudflareSuppressionSourceSync($source))
        ->toThrow(RuntimeException::class);

    expect($suppression->fresh())->not->toBeNull();
});

it('preserves provider sync suppressions when Cloudflare returns an invalid success envelope', function (array $responseBody, string $slug) {
    Http::preventStrayRequests();

    [$project, $source] = cloudflareSuppressionSource($slug, "acc-{$slug}");

    $suppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'preserved@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
    ]);

    Http::fake([
        "https://api.cloudflare.com/client/v4/accounts/acc-{$slug}/email/sending/suppressions*" => Http::response($responseBody),
    ]);

    expect(fn () => runCloudflareSuppressionSourceSync($source))
        ->toThrow(RuntimeException::class);

    expect($suppression->fresh())->not->toBeNull();
})->with([
    'explicit API failure' => [
        ['success' => false, 'result' => []],
        'cf-envelope-failure',
    ],
    'missing result collection' => [
        ['success' => true],
        'cf-envelope-missing-result',
    ],
]);

it('isolates source failures into separate jobs', function () {
    [, $badSource] = cloudflareSuppressionSource('cf-bad', 'acc-bad');
    [, $goodSource] = cloudflareSuppressionSource('cf-good', 'acc-good');

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-bad/email/sending/suppressions*' => Http::response([
            'success' => false,
            'errors' => [['code' => 10001, 'message' => 'invalid token']],
        ], 401),
        'https://api.cloudflare.com/client/v4/accounts/acc-good/email/sending/suppressions*' => Http::sequence()
            ->push(cloudflareSuppressionPageEnvelope([
                'success' => true,
                'result' => [
                    ['id' => 'sup-9', 'email' => 'survivor@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ], 'legacy-next'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]))
            ->push(cloudflareSuppressionPageEnvelope([
                'success' => true,
                'result' => [
                    ['id' => 'sup-9', 'email' => 'survivor@example.com', 'reason' => 'hard_bounce', 'created_at' => now()->toIso8601String(), 'expires_at' => null],
                ],
            ], 'legacy-next'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []])),
    ]);

    expect(fn () => runCloudflareSuppressionSourceSync($badSource))
        ->toThrow(RuntimeException::class);

    runCloudflareSuppressionSourceSync($goodSource);

    expect(Suppression::query()->where('email', 'survivor@example.com')->exists())->toBeTrue();
});

it('does not prune local rows when complete Cloudflare snapshots keep changing', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-unstable', 'acc-unstable');
    $suppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'must-stay@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
    ]);
    $snapshot = fn (string $id): array => cloudflareSuppressionPageEnvelope([
        'success' => true,
        'result' => [[
            'id' => $id,
            'email' => "{$id}@example.com",
            'reason' => 'hard_bounce',
            'created_at' => now()->toIso8601String(),
            'expires_at' => null,
        ]],
    ], 'legacy-next');

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-unstable/email/sending/suppressions*' => Http::sequence()
            ->push($snapshot('first'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]))
            ->push($snapshot('second'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]))
            ->push($snapshot('third'))
            ->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []])),
    ]);

    expect(fn () => runCloudflareSuppressionSourceSync($source))
        ->toThrow(RuntimeException::class);

    $this->assertModelExists($suppression);
});

it('preserves an active colliding suppression and never prunes it later', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-collision', 'acc-collision');
    $sesSource = Source::create([
        'project_id' => $project->id,
        'name' => 'Historical SES source',
        'environment' => 'staging',
        'provider' => 'ses',
        'webhook_token' => 'token-'.str()->random(8),
    ]);
    $suppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $sesSource->id,
        'email' => 'collision@example.com',
        'reason' => 'complaint',
        'event_type' => 'complaint',
    ]);
    $present = cloudflareSuppressionPageEnvelope([
        'success' => true,
        'result' => [[
            'id' => 'cf-collision',
            'email' => 'collision@example.com',
            'reason' => 'hard_bounce',
            'created_at' => now()->toIso8601String(),
            'expires_at' => null,
        ]],
    ], 'legacy-next');
    $empty = cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-collision/email/sending/suppressions*' => Http::sequence()
            ->push($present)
            ->push($empty)
            ->push($present)
            ->push($empty)
            ->push($empty)
            ->push($empty),
    ]);

    runCloudflareSuppressionSourceSync($source);
    runCloudflareSuppressionSourceSync($source);

    expect($suppression->fresh())
        ->not->toBeNull()
        ->source_id->toBe($sesSource->id)
        ->event_type->toBe('complaint')
        ->reason->toBe('complaint');
});

it('reactivates an expired collision then retains it as expired history after stable absence', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-expired-collision', 'acc-expired-collision');
    $historicalSource = Source::create([
        'project_id' => $project->id,
        'name' => 'Historical SES source',
        'environment' => 'staging',
        'provider' => 'ses',
        'default_from_email' => 'receipts@example.com',
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'last_quota' => [
            'Max24HourSend' => 50000,
            'MaxSendRate' => 200,
            'SentLast24Hours' => 25,
        ],
        'last_quota_checked_at' => now(),
        'webhook_token' => 'token-'.str()->random(8),
    ]);
    $suppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $historicalSource->id,
        'email' => 'expired-collision@example.com',
        'reason' => 'complaint',
        'event_type' => 'complaint',
        'expires_at' => now()->subMinute(),
    ]);
    $present = cloudflareSuppressionPageEnvelope([
        'success' => true,
        'result' => [[
            'id' => 'cf-expired-collision',
            'email' => 'expired-collision@example.com',
            'reason' => 'hard_bounce',
            'created_at' => now()->toIso8601String(),
            'expires_at' => null,
        ]],
    ], 'legacy-next');
    $empty = cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-expired-collision/email/sending/suppressions*' => Http::sequence()
            ->push($present)
            ->push($empty)
            ->push($present)
            ->push($empty)
            ->push($empty)
            ->push($empty),
    ]);

    runCloudflareSuppressionSourceSync($source);

    Http::assertSentCount(4);

    expect($suppression->fresh())
        ->source_id->toBe($source->id)
        ->event_type->toBe('provider_sync')
        ->reason->toBe('hard_bounce')
        ->expires_at->toBeNull()
        ->and($project->suppressions()->active()->whereKey($suppression->id)->exists())->toBeTrue();

    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);
    $issued = ApiKey::issue($project, 'Collision test', $historicalSource);
    Queue::fake();

    $this->withToken($issued['plain_text'])->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Expired Collision <expired-collision@example.com>'],
        'subject' => 'Must stay blocked',
        'html' => '<h1>Blocked</h1>',
        'text' => 'Blocked',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('to');

    expect(Email::query()->where('subject', 'Must stay blocked')->exists())->toBeFalse();

    runCloudflareSuppressionSourceSync($source);

    expect($suppression->fresh())->not->toBeNull()
        ->and($suppression->fresh()->expires_at)->not->toBeNull()
        ->and($suppression->fresh()->expires_at->isPast())->toBeTrue();
});

it('fans out a bounded page of unique source jobs and continues with a cursor', function () {
    Queue::fake();

    $sources = collect(range(1, SyncCloudflareSuppressions::BATCH_SIZE + 1))
        ->map(fn (int $index): Source => cloudflareSuppressionSource(
            "cf-fanout-{$index}",
            "acc-fanout-{$index}",
        )[1]);

    (new SyncCloudflareSuppressions)->handle();

    Queue::assertPushed(SyncCloudflareSourceSuppressions::class, SyncCloudflareSuppressions::BATCH_SIZE);
    Queue::assertPushed(
        SyncCloudflareSuppressions::class,
        fn (SyncCloudflareSuppressions $job): bool => $job->afterSourceId === $sources[SyncCloudflareSuppressions::BATCH_SIZE - 1]->id,
    );
});

it('preserves the selected queue connection and queue for source jobs and continuations', function (): void {
    Queue::fake();

    foreach (range(1, SyncCloudflareSuppressions::BATCH_SIZE + 1) as $index) {
        cloudflareSuppressionSource('routing-'.$index, 'routing-account-'.$index);
    }

    (new SyncCloudflareSuppressions)->onConnection('redis')->onQueue('suppression-sync')->handle();

    Queue::assertPushed(SyncCloudflareSourceSuppressions::class, fn ($job): bool => $job->connection === 'redis' && $job->queue === 'suppression-sync');
    Queue::assertPushed(SyncCloudflareSuppressions::class, fn ($job): bool => $job->connection === 'redis' && $job->queue === 'suppression-sync');
});

it('does not prune when the stable snapshot exceeds its wall clock budget', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-deadline', 'acc-deadline');
    $suppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'deadline-preserved@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
    ]);
    $cloudflare = new class extends CloudflareApiClient
    {
        /** @var array<int, float> */
        private array $times = [0.0, 0.0, 61.0];

        protected function monotonicTime(): float
        {
            return array_shift($this->times) ?? 61.0;
        }
    };

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-deadline/email/sending/suppressions*' => Http::response(cloudflareSuppressionPageEnvelope([
            'success' => true,
            'result' => [],
        ])),
    ]);

    expect(fn () => runCloudflareSuppressionSourceSync($source, $cloudflare))
        ->toThrow(RuntimeException::class, 'time budget');

    $this->assertModelExists($suppression);
});

it('does not prune when the source job exhausts its total work budget', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-work-budget', 'acc-work-budget');
    $suppression = Suppression::create([
        'workspace_id' => $project->workspace_id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'work-budget-preserved@example.com',
        'reason' => 'hard_bounce',
        'event_type' => 'provider_sync',
    ]);
    $job = new class($source->id) extends SyncCloudflareSourceSuppressions
    {
        /** @var array<int, float> */
        private array $times = [0.0, 71.0];

        protected function monotonicTime(): float
        {
            return array_shift($this->times) ?? 71.0;
        }
    };

    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/acc-work-budget/email/sending/suppressions*' => Http::response(cloudflareSuppressionPageEnvelope([
            'success' => true,
            'result' => [],
        ])),
    ]);

    expect(fn () => $job->handle(app(CloudflareApiClient::class)))
        ->toThrow(RuntimeException::class, 'work budget');

    $this->assertModelExists($suppression);
});

it('completes a suppression list on a partial one hundredth data page', function () {
    [, $source] = cloudflareSuppressionSource('cf-partial-page-limit', 'acc-partial-page-limit');
    $sequence = Http::sequence();

    foreach (range(1, 99) as $page) {
        $sequence->push(cloudflareSuppressionDataPage($page, 'page-'.($page + 1)));
    }

    $sequence->push(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => [[
        'id' => 'last-partial', 'email' => 'last-partial@example.com', 'reason' => 'hard_bounce',
        'created_at' => now()->toIso8601String(), 'expires_at' => null,
    ]]]));
    Http::fake(['https://api.cloudflare.com/client/v4/accounts/acc-partial-page-limit/email/sending/suppressions*' => $sequence]);
    $suppressions = app(CloudflareApiClient::class)->listSuppressions($source);

    expect($suppressions)->toHaveCount(9901)
        ->and($suppressions[9900]['email'])->toBe('last-partial@example.com');
    Http::assertSentCount(100);
});

it('completes one hundred full data pages when the final cursor is null', function () {
    [, $source] = cloudflareSuppressionSource('cf-terminal-page', 'acc-terminal-page');
    $sequence = Http::sequence();

    foreach (range(1, 100) as $page) {
        $sequence->push(cloudflareSuppressionDataPage($page, $page === 100 ? null : 'page-'.($page + 1)));
    }

    Http::fake(['https://api.cloudflare.com/client/v4/accounts/acc-terminal-page/email/sending/suppressions*' => $sequence]);
    expect(app(CloudflareApiClient::class)->listSuppressions($source))->toHaveCount(10000);
    Http::assertSentCount(100);
});

it('rejects a continuation beyond one hundred pages without expiring local history', function () {
    [$project, $source] = cloudflareSuppressionSource('cf-overflow-page', 'acc-overflow-page');
    $suppression = Suppression::create([
        'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'source_id' => $source->id,
        'email' => 'overflow-preserved@example.com', 'reason' => 'hard_bounce', 'event_type' => 'provider_sync',
    ]);
    $sequence = Http::sequence();

    foreach (range(1, 100) as $page) {
        $sequence->push(cloudflareSuppressionDataPage($page, 'page-'.($page + 1)));
    }

    Http::fake(['https://api.cloudflare.com/client/v4/accounts/acc-overflow-page/email/sending/suppressions*' => $sequence]);
    expect(fn () => runCloudflareSuppressionSourceSync($source))->toThrow(RuntimeException::class, 'data page limit');
    Http::assertSentCount(100);
    $this->assertModelExists($suppression);
});

it('has bounded unique source execution and a non-overlapping dispatcher schedule', function () {
    [, $firstSource] = cloudflareSuppressionSource('cf-unique-a', 'acc-unique-a');
    [, $secondSource] = cloudflareSuppressionSource('cf-unique-b', 'acc-unique-b');
    $dispatcher = new SyncCloudflareSuppressions;
    $sourceJob = new SyncCloudflareSourceSuppressions($firstSource->id);
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === SyncCloudflareSuppressions::class);
    $dispatcherUniqueFor = (new ReflectionClass($dispatcher))
        ->getAttributes(UniqueFor::class)[0]
        ->newInstance()
        ->uniqueFor;
    $sourceUniqueFor = (new ReflectionClass($sourceJob))
        ->getAttributes(UniqueFor::class)[0]
        ->newInstance()
        ->uniqueFor;
    $retryAfterValues = collect(config('queue.connections'))
        ->pluck('retry_after')
        ->filter(fn ($retryAfter): bool => is_int($retryAfter));

    expect($dispatcher)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($sourceJob)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($sourceJob->uniqueId())->not->toBe((new SyncCloudflareSourceSuppressions($secondSource->id))->uniqueId())
        ->and(SyncCloudflareSourceSuppressions::SNAPSHOT_BUDGET_SECONDS)->toBeLessThan(SyncCloudflareSourceSuppressions::WORK_BUDGET_SECONDS)
        ->and(SyncCloudflareSourceSuppressions::WORK_BUDGET_SECONDS)->toBeLessThan($sourceJob->timeout)
        ->and($retryAfterValues->every(fn (int $retryAfter): bool => $dispatcher->timeout < $retryAfter))->toBeTrue()
        ->and($retryAfterValues->every(fn (int $retryAfter): bool => $sourceJob->timeout < $retryAfter))->toBeTrue()
        ->and($dispatcherUniqueFor)->toBeGreaterThan(3600 + $dispatcher->timeout)
        ->and($sourceUniqueFor)->toBeGreaterThan(3600 + $sourceJob->timeout)
        ->and($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('keeps already expired provider history unchanged on repeated absence', function (): void {
    [$project, $source] = cloudflareSuppressionSource('cf-expired-history', 'acc-history');
    $suppression = $project->suppressions()->create(['workspace_id' => $project->workspace_id,
        'source_id' => $source->id, 'email' => 'historical@example.net', 'reason' => 'complaint',
        'event_type' => 'provider_sync', 'expires_at' => now()->subWeek()]);
    $before = $suppression->fresh()->getRawOriginal();
    Http::fake(['*' => Http::response(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => []]))]);

    runCloudflareSuppressionSourceSync($source);
    runCloudflareSuppressionSourceSync($source);

    expect($suppression->fresh()->getRawOriginal())->toBe($before);
});

it('fails malformed provider rows without expiring valid local history', function (mixed $row): void {
    [$project, $source] = cloudflareSuppressionSource('cf-malformed', 'acc-malformed');
    $suppression = $project->suppressions()->create(['workspace_id' => $project->workspace_id,
        'source_id' => $source->id, 'email' => 'preserved@example.net', 'reason' => 'complaint', 'event_type' => 'provider_sync']);
    Http::fake(['*' => Http::response(cloudflareSuppressionPageEnvelope(['success' => true, 'result' => [$row]], 'legacy-next'))]);

    expect(fn () => runCloudflareSuppressionSourceSync($source))->toThrow(RuntimeException::class);
    expect($suppression->fresh()->expires_at)->toBeNull();
})->with([
    'non-object' => [false],
    'missing email' => [['reason' => 'hard_bounce']],
    'empty email' => [['email' => ' ', 'reason' => 'hard_bounce']],
    'invalid expiry' => [['id' => 'invalid-expiry', 'email' => 'invalid@example.net', 'reason' => 'hard_bounce', 'expires_at' => 'not-a-timestamp']],
]);

it('rolls back provider updates and expiry when the total work budget expires during application', function (): void {
    [$project, $source] = cloudflareSuppressionSource('cf-rollback', 'acc-rollback');
    $suppression = $project->suppressions()->create(['workspace_id' => $project->workspace_id,
        'source_id' => $source->id, 'email' => 'existing@example.net', 'reason' => 'complaint', 'event_type' => 'provider_sync']);
    $job = new class($source->id) extends SyncCloudflareSourceSuppressions
    {
        private array $times = [0.0, 0.0, 0.0, 0.0, 71.0];

        protected function monotonicTime(): float
        {
            return array_shift($this->times) ?? 71.0;
        }
    };
    $client = Mockery::mock(CloudflareApiClient::class);
    $client->shouldReceive('listStableSuppressions')->once()->andReturn([
        ['id' => 'new', 'email' => 'new@example.net', 'reason' => 'hard_bounce', 'expires_at' => null, 'read_only' => false, 'scope' => ['type' => 'account']],
    ]);

    expect(fn () => $job->handle($client))->toThrow(RuntimeException::class, 'work budget');
    expect($project->suppressions()->count())->toBe(1)
        ->and($suppression->fresh()->expires_at)->toBeNull();
});
