<?php

use App\Jobs\ReconcileSesEvents;
use App\Jobs\SendQueuedEmail;
use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EventEvidence;
use App\Services\EventPublisher;
use App\Services\Providers\EmailProviderFactory;
use App\Services\SesEventNormalizer;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function immutableSubmissionFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Submission', 'slug' => fake()->uuid()]);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Submission', 'slug' => fake()->uuid()]);
    $source = Source::create(['project_id' => $project->id, 'name' => 'SES', 'webhook_token' => fake()->uuid(),
        'aws_access_key_id' => 'test-key', 'aws_secret_access_key' => 'test-secret',
        'ses_region' => 'eu-central-1', 'ses_configuration_set' => 'test-events']);
    $email = Email::create(['public_id' => 'email_'.fake()->uuid(), 'workspace_id' => $workspace->id,
        'project_id' => $project->id, 'source_id' => $source->id, 'from_email' => 'sender@example.com',
        'subject' => 'Immutable submission', 'status' => 'queued', 'mime_disk' => 'local', 'mime_path' => fake()->uuid().'.eml']);
    $email->recipients()->create(['type' => 'to', 'email' => 'recipient@example.net']);
    Queue::fake();
    Storage::fake('local');
    Storage::disk('local')->put($email->mime_path, "From: sender@example.com\r\nTo: recipient@example.net\r\nX-SES-MESSAGE-TAGS: larasend_attempt_id=attacker\r\n\r\nHello");
    Http::preventStrayRequests();

    return [$source, $email];
}

function immutableSesPayload(string $type, string $messageId, ?string $attempt = null): array
{
    $payload = ['eventType' => $type, 'mail' => ['messageId' => $messageId,
        'timestamp' => '2026-10-30T10:00:00.123456Z', 'destination' => ['recipient@example.net']]];

    if ($attempt !== null) {
        $payload['mail']['tags']['larasend_attempt_id'] = [$attempt];
    }

    return $payload;
}

it('publishes distinct immutable start and acceptance evidence with a server tag durable before provider HTTP', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $level = DB::transactionLevel();
    Http::fake(function (Request $request) use ($email, $level) {
        $started = $email->events()->where('event_type', 'submission_started')->sole();
        expect(DB::transactionLevel())->toBe($level)
            ->and($started->public_event_data['type'])->toBe('submission_started')
            ->and($started->submission_correlation_id)->toBeString()->toHaveLength(48)
            ->and($request['EmailTags'])->toBe([['Name' => 'larasend_attempt_id', 'Value' => $started->submission_correlation_id]]);

        return Http::response(['MessageId' => 'ses-accepted']);
    });
    $job = unserialize(serialize(new SendQueuedEmail($email->id)));
    $job->handle(app(EmailProviderFactory::class));
    $job->handle(app(EmailProviderFactory::class));
    $events = $email->events()->orderBy('email_sequence')->get();
    expect($events->pluck('event_type')->all())->toBe(['submission_started', 'submission_accepted'])
        ->and($events[1]->public_event_data['attempt_id'])->toBe($events[0]->submission_correlation_id)
        ->and($events[0]->public_event_data['type'])->toBe('submission_started')
        ->and($email->fresh()->status)->toBe('sent');
    Http::assertSentCount(1);
});

it('retains retryable rejection and final failure as separate immutable occurrences', function (): void {
    [, $email] = immutableSubmissionFixture();
    Http::fake(['*' => Http::response(['message' => 'Rejected'], 400)]);
    $job = new SendQueuedEmail($email->id);
    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(RequestException::class);
    $job->failed(new RuntimeException('Retries exhausted'));
    $events = $email->events()->orderBy('email_sequence')->get();

    expect($events->pluck('event_type')->all())->toBe(['submission_started', 'submission_rejected', 'failed'])
        ->and($events[1]->public_event_data['details']['retryable'])->toBeTrue()
        ->and($events[2]->public_event_data['details']['final'])->toBeTrue()
        ->and($email->fresh()->status)->toBe('failed');
});

it('retains uncertain submission evidence without automatically resending', function (): void {
    [, $email] = immutableSubmissionFixture();
    $requests = 0;
    Http::fake(function () use (&$requests): never {
        $requests++;
        throw new ConnectionException('Lost provider response');
    });
    $job = new SendQueuedEmail($email->id);
    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(ConnectionException::class);
    $job->handle(app(EmailProviderFactory::class));

    expect($email->events()->orderBy('email_sequence')->pluck('event_type')->all())->toBe(['submission_started', 'delivery_uncertain'])
        ->and($email->fresh()->status)->toBe('sending')->and($requests)->toBe(1);
});

it('uses an early authenticated SES tag to recover a lost provider response without overwriting delivered status', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    Http::fake(function (Request $request) use ($source): never {
        $tag = $request['EmailTags'][0]['Value'];
        $payload = immutableSesPayload('Delivery', 'ses-lost-response', $tag);
        $payload['delivery'] = ['timestamp' => '2026-10-30T10:01:00.999999Z', 'recipients' => ['recipient@example.net']];
        app(SesEventNormalizer::class)->record($source, $payload, 'sns-early-lost');
        throw new ConnectionException('Response lost after acceptance');
    });
    expect(fn () => (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class)))->toThrow(ConnectionException::class);

    expect($email->fresh()->status)->toBe('delivered')->and($email->fresh()->ses_message_id)->toBe('ses-lost-response')
        ->and($email->events()->orderBy('email_sequence')->pluck('event_type')->all())
        ->toBe(['submission_started', 'delivery', 'delivery_uncertain']);
});

it('normalizes SES delivery delays and rendering failures using their documented detail objects', function (string $type, string $wire, array $detail, string $key, string $status): void {
    [$source, $email] = immutableSubmissionFixture();
    $email->update(['ses_message_id' => 'ses-special', 'status' => 'sent']);
    $payload = immutableSesPayload($type, 'ses-special');
    $payload[$key] = $detail;
    $event = app(SesEventNormalizer::class)->record($source, $payload, 'sns-special');
    $data = app(EventEvidence::class)->for($event);

    expect($event->event_type)->toBe($wire)->and($email->fresh()->status)->toBe($status)
        ->and($data['occurred_at'])->toBe($detail['timestamp'] ?? '2026-10-30T10:00:00.123456Z')
        ->and($event->email_sequence)->toBe(1)->and($event->public_event_data)->not->toBeNull();

    if ($wire === 'delivery_delay') {
        expect($data['details'])->toMatchArray(['delay_type' => 'MailboxFull', 'recipient_attribution' => 'recipient',
            'recipients' => [['email' => 'recipient@example.net', 'status' => '4.2.2', 'diagnostic_code' => 'mailbox full']]]);
    } else {
        expect($data['details'])->toMatchArray(['error_message' => 'Missing template variable', 'template_name' => 'welcome']);
    }
})->with([
    ['DeliveryDelay', 'delivery_delay', ['timestamp' => '2026-10-30T10:02:00.654321Z', 'delayType' => 'MailboxFull',
        'delayedRecipients' => [['emailAddress' => 'recipient@example.net', 'status' => '4.2.2', 'diagnosticCode' => 'mailbox full']]], 'deliveryDelay', 'delayed'],
    ['Rendering Failure', 'rendering_failure', ['errorMessage' => 'Missing template variable', 'templateName' => 'welcome'], 'failure', 'failed'],
]);

it('does not fall back to provider message ID when a supplied correlation tag is unknown malformed or foreign', function (string $case): void {
    [$source, $email] = immutableSubmissionFixture();
    $email->update(['ses_message_id' => 'ses-known']);
    $tag = str_repeat('x', 48);

    if ($case === 'foreign') {
        [, $other] = immutableSubmissionFixture();
        app(EventPublisher::class)->record($other, ['event_type' => 'submission_started', 'payload' => [],
            'occurred_at' => now(), 'submission_correlation_id' => $tag]);
    }

    $payload = immutableSesPayload('Delivery', 'ses-known', $tag);
    $payload['delivery'] = ['recipients' => ['recipient@example.net']];

    if ($case === 'multiple') {
        $payload['mail']['tags']['larasend_attempt_id'][] = str_repeat('y', 48);
    }

    if ($case === 'scalar') {
        $payload['mail']['tags']['larasend_attempt_id'] = $tag;
    }

    $event = app(SesEventNormalizer::class)->record($source, $payload, 'sns-invalid-'.$case);
    expect($event->email_id)->toBeNull()->and($event->email_sequence)->toBeNull()
        ->and($email->fresh()->status)->toBe('queued');
})->with(['unknown', 'foreign', 'multiple', 'scalar']);

it('publishes a historical orphan through the tail without mutating duplicate provider evidence', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $payload = immutableSesPayload('Open', 'ses-late');
    $payload['open'] = ['timestamp' => '2026-10-30T10:03:00.123Z'];
    $normalizer = app(SesEventNormalizer::class);
    $orphan = $normalizer->record($source, $payload, 'sns-late');
    $email->update(['ses_message_id' => 'ses-late']);
    $normalizer->reconcile($email->fresh());
    $before = $orphan->fresh()->getRawOriginal();
    $duplicate = $normalizer->record($source, $payload, 'sns-late');

    expect($duplicate->id)->toBe($orphan->id)->and($duplicate->email_sequence)->toBe(1)
        ->and($duplicate->getRawOriginal())->toBe($before)
        ->and($email->fresh()->event_sequence)->toBe(1);
});

it('emits suppression evidence only for a new or reactivated verified email suppression', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $email->update(['ses_message_id' => 'ses-bounce']);
    $payload = immutableSesPayload('Bounce', 'ses-bounce');
    $payload['bounce'] = ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'recipient@example.net']]];
    $normalizer = app(SesEventNormalizer::class);
    $normalizer->record($source, $payload, 'sns-bounce-one');
    $normalizer->record($source, $payload, 'sns-bounce-one');
    $normalizer->record($source, $payload, 'sns-bounce-two');
    expect($email->events()->where('event_type', 'suppress')->count())->toBe(1);
    $suppression = Suppression::query()->sole();
    $suppression->update(['expires_at' => now()->subSecond()]);
    $normalizer->record($source, $payload, 'sns-bounce-three');
    $events = $email->events()->where('event_type', 'suppress')->orderBy('id')->get();

    expect($events)->toHaveCount(2)->and($events[0]->public_event_data['recipient'])->toBe('recipient@example.net')
        ->and($events[0]->public_event_data['details'])->toMatchArray(['reason' => 'hard_bounce', 'transition' => 'created'])
        ->and($events[1]->public_event_data['details']['transition'])->toBe('reactivated');
});

it('uses a distinct server correlation tag for each safe retry while retaining previous attempt evidence', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    Http::fakeSequence()->push(['message' => 'Throttled'], 429)->push(['MessageId' => 'ses-retry']);
    $job = new SendQueuedEmail($email->id);
    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(RequestException::class);
    $first = $email->events()->where('event_type', 'submission_started')->sole();
    $before = $first->fresh()->getRawOriginal();
    $job->handle(app(EmailProviderFactory::class));
    $attempts = $email->events()->where('event_type', 'submission_started')->orderBy('id')->get();

    expect($attempts)->toHaveCount(2)->and($attempts[1]->submission_correlation_id)->not->toBe($first->submission_correlation_id)
        ->and($first->fresh()->getRawOriginal())->toBe($before)
        ->and($email->events()->orderBy('email_sequence')->pluck('event_type')->all())
        ->toBe(['submission_started', 'submission_rejected', 'submission_started', 'submission_accepted']);
});

it('does not attach conflicting tag and provider message matches or overwrite an established provider ID', function (bool $otherMessageMatch): void {
    [$source, $email] = immutableSubmissionFixture();
    $tag = str_repeat('a', 48);
    app(EventPublisher::class)->record($email, ['event_type' => 'submission_started', 'payload' => [],
        'occurred_at' => now(), 'submission_correlation_id' => $tag]);
    $email->update(['ses_message_id' => 'ses-established']);

    if ($otherMessageMatch) {
        $other = $email->replicate(['public_id', 'event_sequence']);
        $other->fill(['public_id' => 'email_other', 'ses_message_id' => 'ses-conflict'])->save();
    }

    $payload = immutableSesPayload('Delivery', 'ses-conflict', $tag);
    $payload['delivery'] = ['recipients' => ['recipient@example.net']];
    $event = app(SesEventNormalizer::class)->record($source, $payload, 'sns-conflict');

    expect($event->email_id)->toBeNull()->and($email->fresh()->ses_message_id)->toBe('ses-established')
        ->and($email->fresh()->event_sequence)->toBe(1);
})->with([false, true]);

it('preserves legacy provider dedup hashes and already published historical identities', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $email->update(['ses_message_id' => 'ses-legacy']);
    $payload = immutableSesPayload('DeliveryDelay', 'ses-legacy');
    $payload['deliveryDelay'] = ['timestamp' => '2026-10-30T10:02:00Z'];
    $legacy = $email->events()->create(['source_id' => $source->id, 'event_type' => 'deliverydelay',
        'ses_message_id' => 'ses-legacy', 'provider_event_id' => hash('sha256', json_encode([$source->id, 'sns-legacy'], JSON_THROW_ON_ERROR)),
        'payload' => $payload, 'occurred_at' => '2026-10-30T10:00:00Z']);
    $before = $legacy->refresh()->getRawOriginal();
    $event = app(SesEventNormalizer::class)->record($source, $payload, 'sns-legacy');

    expect($event->id)->toBe($legacy->id)->and($event->getRawOriginal())->toBe($before)
        ->and($email->events()->count())->toBe(1)->and($email->fresh()->event_sequence)->toBe(0);
});

it('does not create suppressions or suppress evidence until an orphan has verified email ownership', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $payload = immutableSesPayload('Complaint', 'ses-orphan-complaint');
    $payload['complaint'] = ['complainedRecipients' => [['emailAddress' => 'recipient@example.net']]];
    $event = app(SesEventNormalizer::class)->record($source, $payload, 'sns-orphan-complaint');
    expect($event->email_id)->toBeNull()->and(Suppression::query()->count())->toBe(0);
    $email->update(['ses_message_id' => 'ses-orphan-complaint']);
    app(SesEventNormalizer::class)->reconcile($email->fresh());

    expect($event->fresh()->email_sequence)->toBe(1)->and(Suppression::query()->count())->toBe(1)
        ->and($email->events()->where('event_type', 'suppress')->count())->toBe(1);
});

it('does not emit suppression transitions for active manual or foreign source ownership', function (bool $foreign): void {
    [$source, $email] = immutableSubmissionFixture();
    $otherSource = $foreign ? Source::create(['project_id' => $source->project_id, 'name' => 'Other',
        'environment' => 'dev', 'webhook_token' => fake()->uuid()]) : null;
    $suppression = Suppression::create(['workspace_id' => $email->workspace_id, 'project_id' => $email->project_id,
        'source_id' => $otherSource?->id, 'email_id' => null, 'email' => 'recipient@example.net',
        'reason' => 'manual', 'event_type' => 'manual']);
    $before = $suppression->refresh()->getRawOriginal();
    $email->update(['ses_message_id' => 'ses-protected']);
    $payload = immutableSesPayload('Bounce', 'ses-protected');
    $payload['bounce'] = ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'recipient@example.net']]];
    app(SesEventNormalizer::class)->record($source, $payload, 'sns-protected');

    expect($suppression->fresh()->getRawOriginal())->toBe($before)
        ->and($email->events()->where('event_type', 'suppress')->count())->toBe(0);
})->with([false, true]);

it('bounds each orphan reconciliation batch without skipping the remaining older IDs', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $email->update(['ses_message_id' => 'ses-batch']);

    foreach (range(1, 101) as $number) {
        EmailEvent::create(['source_id' => $source->id, 'event_type' => 'open', 'ses_message_id' => 'ses-batch',
            'provider_event_id' => hash('sha256', 'batch-'.$number), 'payload' => immutableSesPayload('Open', 'ses-batch'),
            'occurred_at' => now(), 'contract_version' => 2]);
    }

    $normalizer = app(SesEventNormalizer::class);
    $normalizer->reconcile($email->fresh());
    expect($email->events()->count())->toBe(100)->and($email->fresh()->event_sequence)->toBe(100);
    $normalizer->reconcile($email->fresh());
    expect($email->events()->count())->toBe(101)->and($email->fresh()->event_sequence)->toBe(101);
});

it('publishes pre deduplication SES orphans with explicit SES origin and exact provider occurrence time', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $email->update(['ses_message_id' => 'ses-before-dedup']);
    $payload = immutableSesPayload('Delivery', 'ses-before-dedup');
    $payload['delivery'] = ['timestamp' => '2026-10-30T10:05:00.654321Z', 'recipients' => ['recipient@example.net']];
    $orphan = EmailEvent::create(['source_id' => $source->id, 'event_type' => 'delivery',
        'ses_message_id' => 'ses-before-dedup', 'payload' => $payload, 'occurred_at' => '2026-10-30T10:05:00Z']);
    app(SesEventNormalizer::class)->reconcile($email->fresh());
    $evidence = app(EventEvidence::class)->for($orphan->fresh());

    expect($orphan->fresh()->email_sequence)->toBe(1)->and($evidence['origin'])->toBe('ses')
        ->and($evidence['occurred_at'])->toBe('2026-10-30T10:05:00.654321Z')
        ->and($orphan->fresh()->provider_event_id)->toBeNull();
});

it('does not use public IDs MIME headers or recipient matches as provider correlation proof', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $payload = immutableSesPayload('Delivery', 'ses-unrelated');
    $payload['mail']['headers'] = [['name' => 'X-Larasend-Email-Id', 'value' => $email->public_id]];
    $payload['mail']['tags']['email_id'] = [$email->public_id];
    $payload['delivery'] = ['recipients' => ['recipient@example.net']];
    $event = app(SesEventNormalizer::class)->record($source, $payload, 'sns-unrelated');

    expect($event->email_id)->toBeNull()->and($email->fresh()->ses_message_id)->toBeNull()
        ->and($email->fresh()->status)->toBe('queued');
});

it('does not guess between duplicate source and provider message IDs', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $email->update(['ses_message_id' => 'ses-not-unique']);
    $other = $email->replicate(['public_id', 'event_sequence']);
    $other->fill(['public_id' => 'email_duplicate_id'])->save();
    $payload = immutableSesPayload('Delivery', 'ses-not-unique');
    $payload['delivery'] = ['recipients' => ['recipient@example.net']];
    $event = app(SesEventNormalizer::class)->record($source, $payload, 'sns-not-unique');

    expect($event->email_id)->toBeNull()->and($email->fresh()->status)->toBe('queued')
        ->and($other->fresh()->status)->toBe('queued');
});

it('continues past invalid orphan batches within a finite bound and retries without sending or duplicating evidence', function (): void {
    [$source, $email] = immutableSubmissionFixture();
    $email->update(['ses_message_id' => 'ses-invalid-batch']);

    foreach (range(1, 101) as $number) {
        EmailEvent::create(['source_id' => $source->id, 'event_type' => 'open', 'ses_message_id' => 'ses-invalid-batch',
            'provider_event_id' => hash('sha256', 'invalid-'.$number),
            'payload' => immutableSesPayload('Open', 'ses-invalid-batch', str_repeat('z', 48)),
            'occurred_at' => now(), 'contract_version' => 2]);
    }

    $tail = EmailEvent::create(['source_id' => $source->id, 'event_type' => 'delivery', 'ses_message_id' => 'ses-invalid-batch',
        'provider_event_id' => hash('sha256', 'valid-tail'), 'payload' => immutableSesPayload('Delivery', 'ses-invalid-batch'),
        'occurred_at' => now(), 'contract_version' => 2]);
    $this->mock(EmailProviderFactory::class)->shouldNotReceive('forSource');
    $normalizer = app(SesEventNormalizer::class);
    $normalizer->reconcile($email->fresh());
    Queue::assertPushed(ReconcileSesEvents::class, 1);
    $continuation = Queue::pushed(ReconcileSesEvents::class)->first();
    expect($continuation->afterEventId)->toBe($tail->id - 2)->and($continuation->throughEventId)->toBe($tail->id);
    $later = EmailEvent::create(['source_id' => $source->id, 'event_type' => 'open', 'ses_message_id' => 'ses-invalid-batch',
        'provider_event_id' => hash('sha256', 'after-finite-bound'), 'payload' => immutableSesPayload('Open', 'ses-invalid-batch'),
        'occurred_at' => now(), 'contract_version' => 2]);
    $continuation->handle($normalizer);
    $continuation->handle($normalizer);

    expect($tail->fresh()->email_id)->toBe($email->id)->and($tail->fresh()->email_sequence)->toBe(1)
        ->and(EmailEvent::query()->whereNull('email_id')->count())->toBe(102)
        ->and($later->fresh()->email_id)->toBeNull()
        ->and($email->fresh()->event_sequence)->toBe(1);
    Queue::assertPushed(ReconcileSesEvents::class, 1);
    Http::assertNothingSent();
});

it('never reclassifies or resends accepted mail when a reconciliation continuation enqueue fails', function (): void {
    [$source, $email] = immutableSubmissionFixture();

    foreach (range(1, 101) as $number) {
        EmailEvent::create(['source_id' => $source->id, 'event_type' => 'open', 'ses_message_id' => 'ses-continuation-outage',
            'provider_event_id' => hash('sha256', 'outage-'.$number),
            'payload' => immutableSesPayload('Open', 'ses-continuation-outage', str_repeat('z', 48)),
            'occurred_at' => now(), 'contract_version' => 2]);
    }

    $realDispatcher = app(Dispatcher::class);
    $outage = new RuntimeException('Reconciliation broker unavailable');
    $dispatcher = $this->mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) use ($realDispatcher, $outage) {
        if ($job instanceof ReconcileSesEvents) {
            throw $outage;
        }

        return $realDispatcher->dispatch($job);
    });
    Http::fake(['*' => Http::response(['MessageId' => 'ses-continuation-outage'])]);
    $job = new SendQueuedEmail($email->id);

    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(RuntimeException::class, 'Reconciliation broker unavailable');
    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(RuntimeException::class, 'Reconciliation broker unavailable');
    expect($email->fresh()->status)->toBe('sent')->and($email->fresh()->ses_message_id)->toBe('ses-continuation-outage')
        ->and($email->events()->orderBy('email_sequence')->pluck('event_type')->all())->toBe(['submission_started', 'submission_accepted']);
    Http::assertSentCount(1);
});
