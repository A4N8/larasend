<?php

use App\Exceptions\SuppressionProviderUnavailableException;
use App\Exceptions\SuppressionRemovalException;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Suppression;
use App\Models\User;
use App\Services\SuppressionRemovalService;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function suppressionApiFixture(?array $scopes = ['manage:suppressions'], bool $sourceBound = true): array
{
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());
    $source = $project->sources()->firstOrFail();
    $issued = ApiKey::issue($project, 'Suppression integration', $sourceBound ? $source : null, $scopes ?? []);
    if ($scopes === null) {
        $issued['api_key']->forceFill(['scopes' => null])->save();
    }
    $suppression = $project->suppressions()->create([
        'workspace_id' => $project->workspace_id, 'source_id' => $source->id,
        'email' => 'recipient@example.com', 'reason' => 'complaint', 'event_type' => 'Complaint',
    ]);

    return [$issued, $suppression, $project];
}

it('requires explicit suppression management scope including for legacy and empty keys', function (?array $scopes) {
    [$issued, $suppression] = suppressionApiFixture($scopes);

    $this->withToken($issued['plain_text'])->deleteJson("/api/suppressions/{$suppression->id}")->assertForbidden();
    expect($suppression->fresh())->not->toBeNull();
})->with([[null], [[]], [['send']], [['read:activity']], [['send', 'read:activity']]]);

it('removes a suppression only through the provider-aware service', function () {
    [$issued, $suppression] = suppressionApiFixture();
    $service = Mockery::mock(SuppressionRemovalService::class);
    $service->shouldReceive('remove')->once()->withArgs(fn (Suppression $record) => $record->is($suppression))
        ->andReturnUsing(fn (Suppression $record) => $record->delete());
    app()->instance(SuppressionRemovalService::class, $service);
    $key = $issued['api_key'];
    $identity = $key->only(['key_hash', 'prefix', 'source_id', 'scopes']);

    $this->withToken($issued['plain_text'])->deleteJson("/api/suppressions/{$suppression->id}")->assertNoContent();

    expect($suppression->fresh())->toBeNull()->and($key->fresh()->only(array_keys($identity)))->toBe($identity);
});

it('preserves the local suppression when provider removal is refused or unavailable', function (string $exception, int $status) {
    [$issued, $suppression] = suppressionApiFixture();
    $service = Mockery::mock(SuppressionRemovalService::class);
    $service->shouldReceive('remove')->once()->andThrow(new $exception('Provider verification failed.'));
    app()->instance(SuppressionRemovalService::class, $service);

    $this->withToken($issued['plain_text'])->deleteJson("/api/suppressions/{$suppression->id}")
        ->assertStatus($status)->assertJsonPath('message', 'Provider verification failed.');

    expect($suppression->fresh())->not->toBeNull();
})->with([[SuppressionRemovalException::class, 409], [SuppressionProviderUnavailableException::class, 503]]);

it('isolates removal by project and by the api key source', function (string $mismatch) {
    [$issued, $suppression, $project] = suppressionApiFixture();
    if ($mismatch === 'project') {
        $other = app(ProjectContext::class)->projectFor(User::factory()->create());
        $suppression->forceFill(['project_id' => $other->id, 'workspace_id' => $other->workspace_id, 'source_id' => $other->sources()->sole()->id])->save();
    } elseif ($mismatch === 'source') {
        $other = Project::create(['workspace_id' => $project->workspace_id, 'name' => 'Other', 'slug' => 'other', 'default_environment' => 'prod']);
        $source = app(ProjectContext::class)->currentSource($other);
        $suppression->forceFill(['source_id' => $source->id])->save();
    } else {
        $suppression->forceFill(['source_id' => null])->save();
    }

    $this->withToken($issued['plain_text'])->deleteJson("/api/suppressions/{$suppression->id}")->assertNotFound();
    expect($suppression->fresh())->not->toBeNull();
})->with(['project', 'source', 'global']);

it('keeps legacy keys limited to send and activity and new keys limited to their explicit grants', function () {
    $legacy = ApiKey::make(['scopes' => null]);
    $empty = ApiKey::make(['scopes' => []]);
    expect($legacy->allows('send'))->toBeTrue()->and($legacy->allows('read:activity'))->toBeTrue()
        ->and($legacy->allows('manage:suppressions'))->toBeFalse()
        ->and($empty->allows('manage:suppressions'))->toBeFalse();
    [$issued] = suppressionApiFixture(['manage:suppressions']);
    $this->withToken($issued['plain_text'])->getJson('/api/emails')->assertForbidden();
    $this->withToken($issued['plain_text'])->postJson('/api/emails', [])->assertForbidden();
});

it('allows a project-wide key with explicit management permission to remove an unbound project suppression', function () {
    [$issued, $suppression] = suppressionApiFixture(['manage:suppressions'], false);
    $suppression->forceFill(['source_id' => null])->save();
    $service = Mockery::mock(SuppressionRemovalService::class);
    $service->shouldReceive('remove')->once()->andReturnUsing(fn (Suppression $record) => $record->delete());
    app()->instance(SuppressionRemovalService::class, $service);

    $this->withToken($issued['plain_text'])->deleteJson("/api/suppressions/{$suppression->id}")->assertNoContent();
});

it('does not remove a blocker if its source changes between route binding and the service lock', function () {
    [$issued, $suppression] = suppressionApiFixture();
    $changed = false;
    Suppression::retrieved(function (Suppression $bound) use ($suppression, &$changed): void {
        if (! $changed && $bound->id === $suppression->id) {
            $changed = true;
            DB::table('suppressions')->where('id', $bound->id)->update(['source_id' => null]);
        }
    });
    Http::fake();

    $this->withToken($issued['plain_text'])->deleteJson("/api/suppressions/{$suppression->id}")->assertConflict();

    expect($suppression->fresh())->not->toBeNull();
    Http::assertNothingSent();
});

it('returns actionable IAM guidance after an SES denial without exposing the provider body', function () {
    [$issued, $suppression] = suppressionApiFixture();
    $suppression->source->update(['aws_access_key_id' => 'test-key', 'aws_secret_access_key' => 'test-secret']);
    Http::fake(['*' => Http::response(['message' => 'Sensitive provider details'], 403)]);

    $response = $this->withToken($issued['plain_text'])->deleteJson("/api/suppressions/{$suppression->id}")->assertServiceUnavailable();

    expect($response->json('message'))->toContain('ses:DeleteSuppressedDestination')->not->toContain('Sensitive provider details')
        ->and($suppression->fresh())->not->toBeNull();
});
