<?php

use App\Models\ApiKey;
use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\User;
use App\Services\EventEvidence;
use App\Services\EventPublisher;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function eventHistoryFixture(array $scopes = ['read:activity']): array
{
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());
    $source = $project->sources()->sole();
    $issued = ApiKey::issue($project, 'History reader', $source, $scopes);
    $email = $project->emails()->create([
        'public_id' => 'email_'.str()->random(24), 'workspace_id' => $project->workspace_id, 'source_id' => $source->id,
        'from_email' => 'sender@example.com', 'subject' => 'History', 'status' => 'sent', 'html' => '<p>Private email body</p>',
    ]);
    Queue::fake();

    return [$email, $issued];
}

function eventHistoryRecord(Email $email, string $type = 'open'): EmailEvent
{
    return app(EventPublisher::class)->record($email, [
        'source_id' => $email->source_id, 'event_type' => $type,
        'provider_event_id' => hash('sha256', str()->random(24)), 'occurred_at' => now(),
        'payload' => [],
    ]);
}

it('returns bounded canonical event evidence and a separate mutable email snapshot without fetching body fields', function () {
    [$email, $issued] = eventHistoryFixture();
    $event = eventHistoryRecord($email);
    $expected = app(EventEvidence::class)->for($event);
    Queue::fake();
    Http::preventStrayRequests();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events")
        ->assertSuccessful()
        ->assertJsonPath('meta.email_snapshot.status', 'sent')->assertJsonPath('meta.pagination.has_more', false)
        ->assertJsonPath('meta.reconciliation.through_sequence', 1)->assertJsonPath('meta.reconciliation.completeness', 'stored_events_only')
        ->assertJsonPath('meta.reconciliation.retention_days', 731)->assertJsonPath('meta.reconciliation.automatic_deletion', false);

    expect($response->json('data.0'))->toEqual($expected)
        ->and($response->getContent())->not->toContain('Private email body')
        ->and(collect($queries)->filter(fn (string $sql): bool => preg_match('/select\s+\*\s+from\s+["`]?emails["`]?/i', $sql) === 1))->toBeEmpty();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('finishes a stable snapshot then catches new sequence evidence in the next polling checkpoint', function () {
    [$email, $issued] = eventHistoryFixture();
    $first = eventHistoryRecord($email);
    $second = eventHistoryRecord($email, 'delivery');
    $page = $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events?limit=1")
        ->assertJsonPath('data.0.id', 'evt_'.$first->id)->assertJsonPath('meta.pagination.has_more', true);
    $third = eventHistoryRecord($email, 'click');
    $cursor = urlencode($page->json('meta.pagination.next_cursor'));
    $last = $this->getJson("/api/emails/{$email->public_id}/events?limit=1&cursor={$cursor}")
        ->assertJsonPath('data.0.id', 'evt_'.$second->id)->assertJsonPath('meta.pagination.has_more', false)
        ->assertJsonPath('meta.reconciliation.through_sequence', 2);
    $checkpoint = urlencode($last->json('meta.reconciliation.checkpoint'));
    $this->getJson("/api/emails/{$email->public_id}/events?cursor={$checkpoint}")
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'evt_'.$third->id);
});

it('does not skip an older orphan linked after the legacy creation-id baseline was scanned', function () {
    [$email, $issued] = eventHistoryFixture();
    $orphan = EmailEvent::create(['source_id' => $email->source_id, 'event_type' => 'delivery', 'payload' => [], 'occurred_at' => now()->subDay()]);
    $legacy = $email->events()->create(['source_id' => $email->source_id, 'event_type' => 'send', 'payload' => [], 'occurred_at' => now()->subHour()]);
    $page = $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events?limit=1")
        ->assertJsonPath('data.0.id', 'evt_'.$legacy->id)->assertJsonPath('data.0.contract_version', 1)
        ->assertJsonPath('data.0.origin', 'legacy')->assertJsonPath('data.0.sequence', null);
    app(EventPublisher::class)->publish($orphan, $email);

    $cursor = urlencode($page->json('meta.reconciliation.checkpoint'));
    $this->getJson("/api/emails/{$email->public_id}/events?cursor={$cursor}")
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'evt_'.$orphan->id)->assertJsonPath('data.0.sequence', 1);
});

it('keeps legacy baseline pagination separate from the new sequence tail', function () {
    [$email, $issued] = eventHistoryFixture();
    $legacy = [];
    foreach (range(1, 2) as $number) {
        $legacy[] = $email->events()->create(['source_id' => $email->source_id, 'event_type' => 'legacy_'.$number, 'payload' => [], 'occurred_at' => now()->subDay()]);
    }
    $new = eventHistoryRecord($email);
    $first = $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events?limit=1")->assertJsonPath('data.0.id', 'evt_'.$legacy[0]->id);
    $cursor = urlencode($first->json('meta.pagination.next_cursor'));
    $second = $this->getJson("/api/emails/{$email->public_id}/events?limit=1&cursor={$cursor}")->assertJsonPath('data.0.id', 'evt_'.$legacy[1]->id);
    $cursor = urlencode($second->json('meta.pagination.next_cursor'));
    $this->getJson("/api/emails/{$email->public_id}/events?limit=1&cursor={$cursor}")->assertJsonPath('data.0.id', 'evt_'.$new->id)->assertJsonPath('meta.pagination.has_more', false);
});

it('finds an older orphan linked while a multi-page legacy baseline is still being scanned', function () {
    [$email, $issued] = eventHistoryFixture();
    $orphan = EmailEvent::create(['source_id' => $email->source_id, 'event_type' => 'delivery', 'payload' => [], 'occurred_at' => now()->subDay()]);
    foreach (range(1, 2) as $number) {
        $email->events()->create(['source_id' => $email->source_id, 'event_type' => 'legacy_'.$number, 'payload' => [], 'occurred_at' => now()]);
    }
    $first = $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events?limit=1")->assertJsonPath('meta.pagination.has_more', true);
    app(EventPublisher::class)->publish($orphan, $email);

    $cursor = urlencode($first->json('meta.pagination.next_cursor'));
    $last = $this->getJson("/api/emails/{$email->public_id}/events?limit=1&cursor={$cursor}")->assertJsonPath('meta.pagination.has_more', false);
    $checkpoint = urlencode($last->json('meta.reconciliation.checkpoint'));
    $this->getJson("/api/emails/{$email->public_id}/events?cursor={$checkpoint}")->assertJsonPath('data.0.id', 'evt_'.$orphan->id);
});

it('returns an empty resumable checkpoint when caught up rather than resending any mail', function () {
    [$email, $issued] = eventHistoryFixture();
    $first = $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events")->assertJsonCount(0, 'data');
    $cursor = urlencode($first->json('meta.reconciliation.checkpoint'));
    $this->getJson("/api/emails/{$email->public_id}/events?cursor={$cursor}")->assertJsonCount(0, 'data')->assertJsonPath('meta.pagination.has_more', false);
    Queue::assertNothingPushed();
});

it('rejects foreign-key cursors and does not leak events across source or project boundaries', function () {
    [$email, $issued] = eventHistoryFixture();
    eventHistoryRecord($email);
    $response = $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events");
    $cursor = urlencode($response->json('meta.reconciliation.checkpoint'));
    $replacement = ApiKey::issue($email->project, 'Other identity', $email->source, ['read:activity']);
    $this->withToken($replacement['plain_text'])->getJson("/api/emails/{$email->public_id}/events?cursor={$cursor}")->assertUnprocessable();
    $foreign = app(ProjectContext::class)->projectFor(User::factory()->create());
    $other = ApiKey::issue($foreign, 'Other project', $foreign->sources()->sole(), ['read:activity']);
    $this->withToken($other['plain_text'])->getJson("/api/emails/{$email->public_id}/events")->assertNotFound();
    $sameProjectSource = $email->project->sources()->create(['name' => 'Other', 'environment' => 'staging', 'webhook_token' => 'other-'.str()->random(12)]);
    $sameProjectKey = ApiKey::issue($email->project, 'Other source', $sameProjectSource, ['read:activity']);
    $this->withToken($sameProjectKey['plain_text'])->getJson("/api/emails/{$email->public_id}/events")->assertNotFound();
});

it('authenticates event-history reads before resolving email identity', function () {
    [$email] = eventHistoryFixture();
    $this->getJson("/api/emails/{$email->public_id}/events")->assertUnauthorized();
    $this->getJson('/api/emails/email_missing/events')->assertUnauthorized();
});

it('requires activity permission for event-only history', function (array $scopes) {
    [$email, $issued] = eventHistoryFixture($scopes);
    $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events")->assertForbidden();
})->with([[[]], [['send']], [['manage:suppressions']]]);

it('validates cursor and bounded history page sizes', function (string $query) {
    [$email, $issued] = eventHistoryFixture();
    $this->withToken($issued['plain_text'])->getJson("/api/emails/{$email->public_id}/events?{$query}")->assertUnprocessable();
})->with(['limit=0', 'limit=101', 'limit=-1', 'limit=wrong', 'cursor=malformed']);
