<?php

use App\Jobs\SyncCloudflareSourceSuppressions;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CloudflareApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function currentCloudflareSource(): Source
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Current CF', 'slug' => 'current-cf']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Current CF', 'slug' => 'current-cf']);

    return Source::create(['project_id' => $project->id, 'name' => 'Current', 'provider' => 'cloudflare',
        'cloudflare_api_token' => 'test-token', 'cloudflare_account_id' => 'current-account', 'webhook_token' => 'current-cf']);
}

function currentCloudflareRow(array $overrides = []): array
{
    return ['id' => 'suppression-id', 'email' => 'blocked@example.net', 'reason' => 'manual',
        'read_only' => false, 'scope' => ['type' => 'account'], 'expires_at' => null, ...$overrides];
}

function currentCloudflarePage(array $rows, ?string $cursor = null): array
{
    return ['success' => true, 'result' => $rows, 'result_info' => ['count' => count($rows), 'per_page' => 100, 'next_cursor' => $cursor]];
}

it('uses the current account-scoped cursor API through short intermediate pages and preserves metadata', function (): void {
    $source = currentCloudflareSource();
    Http::fake(['*' => Http::sequence()->push(currentCloudflarePage([], 'opaque-next'))
        ->push(currentCloudflarePage([currentCloudflareRow()]))]);

    $rows = app(CloudflareApiClient::class)->listSuppressions($source);

    expect($rows)->toHaveCount(1)->and($rows[0]['id'])->toBe('suppression-id')
        ->and($rows[0]['scope'])->toBe(['type' => 'account'])->and($rows[0]['read_only'])->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/current-account/email/sending/suppressions?per_page=100&scope_type=account&cursor=opaque-next');
    Http::assertSentCount(2);
});

it('preserves history when scope mutability expiry or pagination cannot establish a complete account snapshot', function (array $row, mixed $cursor): void {
    $source = currentCloudflareSource();
    $existing = $source->project->suppressions()->create(['workspace_id' => $source->project->workspace_id,
        'source_id' => $source->id, 'email' => 'history@example.net', 'reason' => 'hard_bounce', 'event_type' => 'provider_sync',
        'provider_metadata' => ['id' => 'old', 'scope' => ['type' => 'account'], 'read_only' => true]]);
    $body = currentCloudflarePage([$row]);
    $body['result_info']['next_cursor'] = $cursor;
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => (new SyncCloudflareSourceSuppressions($source->id))->handle(app(CloudflareApiClient::class)))
        ->toThrow(RuntimeException::class);
    expect($existing->fresh()->expires_at)->toBeNull()->and($source->project->suppressions()->count())->toBe(1);
})->with([
    'domain scope' => [currentCloudflareRow(['scope' => ['type' => 'sending_domain', 'value' => 'other.example.com']]), null],
    'unknown scope' => [currentCloudflareRow(['scope' => null]), null],
    'missing mutability' => [currentCloudflareRow(['read_only' => null]), null],
    'blank expiry' => [currentCloudflareRow(['expires_at' => '']), null],
    'relative expiry' => [currentCloudflareRow(['expires_at' => 'yesterday']), null],
    'invalid calendar' => [currentCloudflareRow(['expires_at' => '2026-02-31T00:00:00Z']), null],
    'invalid cursor' => [currentCloudflareRow(), 100],
    'cursor cycle' => [currentCloudflareRow(), 'same-cursor'],
]);

it('only expires absent records proven to belong to this account snapshot and preserves metadata', function (): void {
    $source = currentCloudflareSource();
    $project = $source->project;
    $base = ['workspace_id' => $project->workspace_id, 'source_id' => $source->id, 'reason' => 'hard_bounce', 'event_type' => 'provider_sync'];
    $known = $project->suppressions()->create([...$base, 'email' => 'known@example.net',
        'provider_metadata' => ['id' => 'known', 'scope' => ['type' => 'account'], 'read_only' => true]]);
    $unknown = $project->suppressions()->create([...$base, 'email' => 'unknown@example.net']);
    $domain = $project->suppressions()->create([...$base, 'email' => 'domain@example.net',
        'provider_metadata' => ['id' => 'domain', 'scope' => ['type' => 'sending_domain', 'value' => 'other.example.com'], 'read_only' => false]]);
    Http::fake(['*' => Http::response(currentCloudflarePage([currentCloudflareRow()]))]);

    (new SyncCloudflareSourceSuppressions($source->id))->handle(app(CloudflareApiClient::class));

    expect($known->fresh()->expires_at)->not->toBeNull()
        ->and($unknown->fresh()->expires_at)->toBeNull()->and($domain->fresh()->expires_at)->toBeNull()
        ->and($project->suppressions()->where('email', 'blocked@example.net')->sole()->provider_metadata)
        ->toBe(['id' => 'suppression-id', 'scope' => ['type' => 'account'], 'read_only' => false])
        ->and($project->suppressions()->count())->toBe(4);
});

it('rejects duplicate canonical account rows instead of weakening one provider blocker with another', function (): void {
    $source = currentCloudflareSource();
    Http::fake(['*' => Http::response(currentCloudflarePage([
        currentCloudflareRow(), currentCloudflareRow(['id' => 'different-id', 'email' => 'BLOCKED@EXAMPLE.NET', 'expires_at' => '2026-01-01T00:00:00Z']),
    ]))]);

    expect(fn () => app(CloudflareApiClient::class)->listSuppressions($source))->toThrow(RuntimeException::class, 'duplicate');
});
