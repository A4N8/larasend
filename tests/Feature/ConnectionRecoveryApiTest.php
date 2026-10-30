<?php

use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Services\EmailSendService;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function recoveryApiFixture(?array $scopes = ['send', 'read:activity'], bool $bound = true): array
{
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());
    $source = $project->sources()->sole();
    $issued = ApiKey::issue($project, 'Recovery integration', $bound ? $source : null, $scopes ?? []);
    if ($scopes === null) {
        $issued['api_key']->forceFill(['scopes' => null])->save();
    }

    return [$project, $source, $issued];
}

function recoveryApiEmail(Project $project, Source $source, ApiKey $key, string $clientKey = 'stable-attempt'): Email
{
    return $project->emails()->create([
        'public_id' => 'email_'.str()->random(24), 'workspace_id' => $project->workspace_id, 'source_id' => $source->id,
        'from_email' => 'sender@example.com', 'subject' => 'Recovery', 'status' => 'queued', 'html' => '<p>Private body</p>',
        'idempotency_key' => hash('sha256', json_encode([$key->id, $source->id, $clientKey], JSON_THROW_ON_ERROR)),
        'idempotency_hash' => hash('sha256', 'existing-payload'),
    ]);
}

it('returns a durable nonsecret authenticated connection descriptor without creating identity on reads', function () {
    [$project, $source, $issued] = recoveryApiFixture();
    $before = DB::table('larasend_installation')->get()->toArray();
    $first = $this->withToken($issued['plain_text'])->getJson('/api/connection')->assertSuccessful()
        ->assertJsonPath('data.contract_version', 2)->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.api_key_id', $issued['api_key']->id)->assertJsonPath('data.source_id', $source->id)
        ->assertJsonPath('data.source_pinned', true)->assertJsonPath('data.capabilities.send', true)
        ->assertJsonPath('data.capabilities.read:activity', true);
    $second = $this->getJson('/api/connection')->assertSuccessful();

    expect($first->json('data.instance_id'))->toBeUuid()->toBe($second->json('data.instance_id'))
        ->and(DB::table('larasend_installation')->get()->toArray())->toEqual($before)
        ->and($first->getContent())->not->toContain($issued['plain_text'])->not->toContain('key_hash')->not->toContain('webhook_token');
});

it('allows connection discovery with send or activity permissions but denies empty and unrelated grants', function (?array $scopes, int $status) {
    [, , $issued] = recoveryApiFixture($scopes);

    $this->withToken($issued['plain_text'])->getJson('/api/connection')->assertStatus($status);
})->with([[['send'], 200], [['read:activity'], 200], [null, 200], [[], 403], [['manage:suppressions'], 403]]);

it('pins connection discovery to authorized sources and marks an unbound default as unpinned', function () {
    [$project, $source, $issued] = recoveryApiFixture(bound: false);
    $this->withToken($issued['plain_text'])->getJson('/api/connection')->assertJsonPath('data.source_pinned', false);
    $this->getJson("/api/connection?source_id={$source->id}")->assertJsonPath('data.source_pinned', true);
    $other = app(ProjectContext::class)->projectFor(User::factory()->create())->sources()->sole();
    $this->getJson("/api/connection?source_id={$other->id}")->assertNotFound();
});

it('looks up the exact key and source idempotency scope without sending or fetching a body', function () {
    [$project, $source, $issued] = recoveryApiFixture();
    $email = recoveryApiEmail($project, $source, $issued['api_key']);
    Queue::fake();
    Http::preventStrayRequests();

    $response = $this->withToken($issued['plain_text'])->withHeader('Idempotency-Key', 'stable-attempt')
        ->getJson('/api/emails/lookup')->assertSuccessful()->assertJsonPath('data.id', $email->public_id)
        ->assertJsonPath('data.source_id', $source->id)->assertJsonPath('meta.read_only', true);

    expect($response->getContent())->not->toContain('Private body')->not->toContain('html')->not->toContain('idempotency_hash')
        ->and($email->fresh()->status)->toBe('queued')->and($email->events()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('never broadens lookup after credential rotation or a changed default source', function () {
    [$project, $source, $issued] = recoveryApiFixture(bound: false);
    $email = recoveryApiEmail($project, $source, $issued['api_key']);
    $other = $project->sources()->create(['name' => 'Staging', 'environment' => 'staging', 'webhook_token' => 'other-'.str()->random(12)]);
    $project->update(['default_environment' => 'staging']);
    Queue::fake();

    $this->withToken($issued['plain_text'])->withHeader('Idempotency-Key', 'stable-attempt')->getJson('/api/emails/lookup')->assertUnprocessable();
    $this->getJson("/api/emails/lookup?source_id={$other->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found_or_unresolved');
    $this->getJson("/api/emails/lookup?source_id={$source->id}")->assertJsonPath('data.id', $email->public_id);
    $replacement = ApiKey::issue($project, 'Replacement', $source, ['read:activity']);
    $this->withToken($replacement['plain_text'])->getJson('/api/emails/lookup')->assertNotFound();
    Queue::assertNothingPushed();
});

it('requires activity permission for lookup even when a send key knows the client attempt', function () {
    [, , $issued] = recoveryApiFixture(['send']);
    $this->withToken($issued['plain_text'])->withHeader('Idempotency-Key', 'stable-attempt')->getJson('/api/emails/lookup')->assertForbidden();
});

it('rejects invalid lookup correlation keys without side effects', function (string $key) {
    [, , $issued] = recoveryApiFixture();
    Queue::fake();

    $this->withToken($issued['plain_text'])->withHeader('Idempotency-Key', $key)->getJson('/api/emails/lookup')->assertUnprocessable();
    Queue::assertNothingPushed();
})->with(['', str_repeat('a', 256), "bad\xFFkey"]);

it('enforces source binding on existing activity reads', function () {
    [$project, $source, $issued] = recoveryApiFixture();
    $own = recoveryApiEmail($project, $source, $issued['api_key']);
    $other = $project->sources()->create(['name' => 'Other', 'environment' => 'staging', 'webhook_token' => 'other-'.str()->random(12)]);
    $hidden = recoveryApiEmail($project, $other, $issued['api_key']);

    $this->withToken($issued['plain_text'])->getJson('/api/emails')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->public_id);
    $this->getJson("/api/emails/{$hidden->public_id}")->assertNotFound();
});

it('honors an explicit send source and excludes the pin from the existing canonical payload hash', function () {
    [$project, $source, $issued] = recoveryApiFixture(bound: false);
    $payload = ['from' => 'sender@example.com', 'to' => ['recipient@example.com'], 'subject' => 'Pinned', 'text' => 'Body'];
    $canonical = $payload;
    ksort($canonical);
    $email = recoveryApiEmail($project, $source, $issued['api_key']);
    $email->forceFill(['status' => 'sent', 'idempotency_hash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR))])->save();
    $other = $project->sources()->create(['name' => 'Other', 'environment' => 'staging', 'webhook_token' => 'other-'.str()->random(12)]);
    $project->update(['default_environment' => 'staging']);
    Queue::fake();

    $this->withToken($issued['plain_text'])->withHeader('Idempotency-Key', 'stable-attempt')
        ->postJson('/api/emails', [...$payload, 'source_id' => $source->id])->assertAccepted()
        ->assertJsonPath('id', $email->public_id)->assertJsonPath('replayed', true);

    Queue::assertNothingPushed();
});

it('refuses to send through a different source with a bound key', function () {
    [$project, , $issued] = recoveryApiFixture();
    $other = $project->sources()->create(['name' => 'Other', 'environment' => 'staging', 'webhook_token' => 'other-'.str()->random(12)]);
    $service = Mockery::mock(EmailSendService::class);
    $service->shouldNotReceive('send');
    app()->instance(EmailSendService::class, $service);

    $this->withToken($issued['plain_text'])->postJson('/api/emails', [
        'source_id' => $other->id, 'from' => 'sender@example.com', 'to' => ['recipient@example.com'], 'subject' => 'Pinned', 'text' => 'Body',
    ])->assertNotFound();
});

it('returns a stable validation error code without changing the validation error shape', function (array $invalid, array $errors) {
    [, , $issued] = recoveryApiFixture();
    Queue::fake();
    Http::preventStrayRequests();

    $this->withToken($issued['plain_text'])->postJson('/api/emails', $invalid)->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')->assertJsonValidationErrors($errors)
        ->assertJsonStructure(['message', 'errors']);

    expect(Email::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'missing required fields' => [[], ['from', 'to']],
    'missing recipients' => [['from' => 'sender@example.com', 'subject' => 'Validation', 'text' => 'Body'], ['to']],
    'invalid sender mailbox' => [['from' => 'not-an-email', 'to' => ['recipient@example.com'], 'subject' => 'Validation', 'text' => 'Body'], ['from']],
    'invalid recipient mailbox' => [['from' => 'sender@example.com', 'to' => ['not-an-email'], 'subject' => 'Validation', 'text' => 'Body'], ['to.0']],
]);

it('preserves dashboard validation redirects and standard JSON errors', function () {
    $user = User::factory()->create();
    app(ProjectContext::class)->projectFor($user);
    Queue::fake();
    Http::preventStrayRequests();

    $this->actingAs($user)->from('/activity')->post('/send', [])
        ->assertRedirect('/activity')->assertSessionHasErrors(['from', 'to']);
    $this->postJson('/send', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['from', 'to'])->assertJsonMissingPath('error.code');

    expect(Email::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('returns a stable idempotency conflict code without sending', function () {
    [$project, $source, $issued] = recoveryApiFixture();
    recoveryApiEmail($project, $source, $issued['api_key']);
    Queue::fake();

    $this->withToken($issued['plain_text'])->withHeader('Idempotency-Key', 'stable-attempt')->postJson('/api/emails', [
        'from' => 'sender@example.com', 'to' => ['recipient@example.com'], 'subject' => 'Different', 'text' => 'Body',
    ])->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict')
        ->assertJsonPath('message', 'This Idempotency-Key was already used with a different request.');

    Queue::assertNothingPushed();
});

it('identifies a synchronous suppression rejection without exposing recipients or creating a sent record', function () {
    [$project, $source, $issued] = recoveryApiFixture();
    $source->update(['aws_access_key_id' => 'test-key', 'aws_secret_access_key' => 'test-secret', 'last_quota' => ['Max24HourSend' => 1000], 'last_quota_checked_at' => now()]);
    $project->domains()->create(['domain' => 'example.com', 'status' => 'verified']);
    $project->suppressions()->create(['workspace_id' => $project->workspace_id, 'source_id' => $source->id, 'email' => 'recipient@example.com', 'reason' => 'complaint', 'event_type' => 'Complaint']);
    Queue::fake();
    Http::preventStrayRequests();

    $response = $this->withToken($issued['plain_text'])->postJson('/api/emails', [
        'from' => 'sender@example.com', 'to' => ['recipient@example.com'], 'subject' => 'Suppressed', 'text' => 'Body',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'recipient_suppressed')
        ->assertJsonPath('errors.to.0', 'One or more recipients are suppressed.');

    expect($response->getContent())->not->toContain('recipient@example.com')->and($project->emails()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('reports a new credential identity after the real rotation endpoint and cannot look up the old key scope', function () {
    [$project, $source, $issued] = recoveryApiFixture();
    recoveryApiEmail($project, $source, $issued['api_key']);
    $before = $this->withToken($issued['plain_text'])->getJson('/api/connection')->assertSuccessful()->json('data');
    Queue::fake();

    $rotation = $this->actingAs($project->workspace->owner)->post("/projects/{$project->slug}/api-keys/{$issued['api_key']->id}/rotate")
        ->assertRedirect()->assertSessionHas('newApiKey');
    $replacement = session('newApiKey');
    $after = $this->withToken($replacement)->getJson('/api/connection')->assertSuccessful()->json('data');

    expect($after['api_key_id'])->not->toBe($before['api_key_id'])
        ->and($after['instance_id'])->toBe($before['instance_id'])
        ->and($issued['api_key']->fresh())->toBeNull();
    $this->withHeader('Idempotency-Key', 'stable-attempt')->getJson('/api/emails/lookup')->assertNotFound();
    $this->withToken($issued['plain_text'])->getJson('/api/connection')->assertUnauthorized();
    Queue::assertNothingPushed();
});

it('preserves shared rate-limit retry guidance on read-only recovery', function () {
    config(['larasend.api_auth_rate_limit_per_minute' => 1]);
    [, , $issued] = recoveryApiFixture();
    Queue::fake();
    $this->withToken($issued['plain_text'])->getJson('/api/connection')->assertSuccessful();

    $this->withHeader('Idempotency-Key', 'stable-attempt')->getJson('/api/emails/lookup')
        ->assertTooManyRequests()->assertHeader('Retry-After');

    Queue::assertNothingPushed();
});
