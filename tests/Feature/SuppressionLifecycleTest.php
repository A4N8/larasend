<?php

use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EmailSendService;
use App\Services\SesEventNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function suppressionLifecycleFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Lifecycle', 'slug' => 'lifecycle']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Lifecycle', 'slug' => 'lifecycle']);
    $source = Source::create(['project_id' => $project->id, 'name' => 'SES', 'provider' => 'ses', 'environment' => 'prod',
        'webhook_token' => 'lifecycle', 'aws_access_key_id' => 'testing', 'aws_secret_access_key' => 'testing',
        'default_from_email' => 'sender@example.com', 'last_quota' => ['Max24HourSend' => 10000, 'MaxSendRate' => 100, 'SentLast24Hours' => 0], 'last_quota_checked_at' => now()]);
    $project->domains()->create(['domain' => 'example.com', 'status' => 'verified', 'verified_at' => now()]);
    $email = Email::create(['public_id' => 'email_suppression', 'project_id' => $project->id, 'workspace_id' => $workspace->id,
        'source_id' => $source->id, 'status' => 'sent', 'ses_message_id' => 'ses-suppression', 'from_email' => 'sender@example.com', 'subject' => 'Suppression']);
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');

    return [$project, $source, $email];
}

function lifecycleBlocker(Project $project, string $email, array $values = []): Suppression
{
    return $project->suppressions()->create(['workspace_id' => $project->workspace_id,
        'email' => $email, 'reason' => 'hard_bounce', 'event_type' => 'bounce', ...$values]);
}

it('retains expired suppressions but blocks only active canonical matches across to cc and bcc', function (string $field): void {
    [$project, $source] = suppressionLifecycleFixture();
    $blocker = lifecycleBlocker($project, 'block@example.net');
    DB::table('suppressions')->where('id', $blocker->id)->update(['email' => ' BLOCK@Example.NET ']);
    $payload = ['from' => 'sender@example.com', 'to' => ['other@example.net'], 'subject' => 'Testing', 'text' => 'Testing', $field => ['Block@example.net']];

    expect(fn () => app(EmailSendService::class)->send($project, $source, $payload))->toThrow(ValidationException::class, 'suppressed recipients');
    $blocker->forceFill(['expires_at' => now()])->save();
    app(EmailSendService::class)->send($project, $source, $payload);

    expect($blocker->fresh())->not->toBeNull()->and($project->suppressions()->active()->count())->toBe(0);
})->with(['to', 'cc', 'bcc']);

it('records every bounce or complaint recipient once while retaining historical canonical bytes', function (string $type, string $recipientKey, string $reason): void {
    [$project, $source, $email] = suppressionLifecycleFixture();
    $existing = lifecycleBlocker($project, 'first@example.net', ['expires_at' => now()->subDay()]);
    DB::table('suppressions')->where('id', $existing->id)->update(['email' => ' First@Example.NET ']);
    $payload = ['eventType' => ucfirst($type), 'mail' => ['messageId' => 'ses-suppression'],
        $type => ['bounceType' => 'Permanent', $recipientKey => [
            ['emailAddress' => 'FIRST@example.net'], ['emailAddress' => 'second@example.net'], ['emailAddress' => 'second@EXAMPLE.NET'],
        ]]];
    app(SesEventNormalizer::class)->record($source, $payload, 'sns-all-recipients');
    app(SesEventNormalizer::class)->record($source, $payload, 'sns-all-recipients');

    expect($project->suppressions()->count())->toBe(2)
        ->and($existing->fresh()->email)->toBe(' First@Example.NET ')
        ->and($existing->fresh()->expires_at)->toBeNull()
        ->and($existing->fresh()->reason)->toBe($reason)
        ->and($project->suppressions()->where('email', 'second@example.net')->sole()->email_id)->toBe($email->id)
        ->and(EmailEvent::query()->count())->toBe(1);
})->with([['bounce', 'bouncedRecipients', 'hard_bounce'], ['complaint', 'complainedRecipients', 'complaint']]);

it('reconciles all orphaned bounce recipients and never downgrades an existing complaint', function (): void {
    [$project, $source, $email] = suppressionLifecycleFixture();
    lifecycleBlocker($project, 'complaint@example.net', ['reason' => 'complaint', 'event_type' => 'complaint']);
    $email->forceFill(['ses_message_id' => null])->save();
    app(SesEventNormalizer::class)->record($source, ['eventType' => 'Bounce', 'mail' => ['messageId' => 'ses-suppression'],
        'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'complaint@example.net'], ['emailAddress' => 'late@example.net']]]], 'sns-orphan');
    $email->forceFill(['ses_message_id' => 'ses-suppression'])->save();
    app(SesEventNormalizer::class)->reconcile($email);

    expect($project->suppressions()->where('email', 'late@example.net')->sole()->email_id)->toBe($email->id)
        ->and($project->suppressions()->where('email', 'complaint@example.net')->sole()->reason)->toBe('complaint');
});

it('preserves active local or foreign provider ownership when SES records a suppression event', function (string $ownership): void {
    [$project, $source, $email] = suppressionLifecycleFixture();
    $otherSource = Source::create(['project_id' => $project->id, 'name' => 'Other', 'provider' => 'ses', 'environment' => 'dev', 'webhook_token' => 'other-owner']);
    $existing = lifecycleBlocker($project, 'protected@example.net', [
        'source_id' => match ($ownership) {
            'unbound', 'manual' => null,
            'foreign' => $otherSource->id,
            default => $source->id,
        },
        'email_id' => $ownership === 'unlinked' ? null : $email->id,
        'reason' => $ownership === 'manual reason' ? 'manual' : 'hard_bounce',
        'event_type' => in_array($ownership, ['manual', 'same-source manual'], true) ? 'manual' : 'bounce',
        'expires_at' => now()->addMonth(),
    ]);
    $before = $existing->fresh()->getRawOriginal();
    $event = app(SesEventNormalizer::class)->record($source, ['eventType' => 'Complaint',
        'mail' => ['messageId' => $email->ses_message_id],
        'complaint' => ['complainedRecipients' => [['emailAddress' => 'PROTECTED@EXAMPLE.NET']]]], 'ownership-event');

    expect($existing->fresh()->getRawOriginal())->toBe($before)
        ->and($event->email_id)->toBe($email->id)
        ->and($event->event_type)->toBe('complaint');
})->with(['unbound', 'manual', 'same-source manual', 'manual reason', 'foreign', 'unlinked']);

it('allows SES to reactivate expired foreign history while keeping its own active complaint stronger than a bounce', function (): void {
    [$project, $source, $email] = suppressionLifecycleFixture();
    $otherSource = Source::create(['project_id' => $project->id, 'name' => 'Old', 'provider' => 'ses', 'environment' => 'dev', 'webhook_token' => 'old-owner']);
    $expired = lifecycleBlocker($project, 'expired@example.net', ['source_id' => $otherSource->id, 'event_type' => 'manual', 'expires_at' => now()->subDay()]);
    $complaint = lifecycleBlocker($project, 'complaint@example.net', ['source_id' => $source->id, 'email_id' => $email->id, 'reason' => 'complaint', 'event_type' => 'complaint']);
    $complaintBefore = $complaint->fresh()->getRawOriginal();
    app(SesEventNormalizer::class)->record($source, ['eventType' => 'Bounce', 'mail' => ['messageId' => $email->ses_message_id],
        'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'expired@example.net'], ['emailAddress' => 'complaint@example.net']]]], 'reactivation');

    expect($expired->fresh()->source_id)->toBe($source->id)
        ->and($expired->fresh()->email_id)->toBe($email->id)
        ->and($expired->fresh()->expires_at)->toBeNull()
        ->and($expired->fresh()->reason)->toBe('hard_bounce')
        ->and($complaint->fresh()->getRawOriginal())->toBe($complaintBefore);
});
