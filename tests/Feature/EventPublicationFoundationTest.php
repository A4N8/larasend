<?php

use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EventEvidence;
use App\Services\EventPublisher;
use App\Services\WebhookDeliveryService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function eventPublicationEmail(): Email
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Events', 'slug' => fake()->uuid()]);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Events', 'slug' => fake()->uuid()]);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Events', 'webhook_token' => fake()->uuid()]);

    return Email::create(['public_id' => 'email_'.fake()->uuid(), 'workspace_id' => $workspace->id,
        'project_id' => $project->id, 'source_id' => $source->id, 'from_email' => 'sender@example.com',
        'subject' => 'Event publication', 'status' => 'sending']);
}

it('assigns per email publication sequences without changing existing legacy evidence', function (): void {
    $email = eventPublicationEmail();
    $legacy = $email->events()->create(['source_id' => $email->source_id, 'event_type' => 'send',
        'payload' => ['legacy' => true], 'occurred_at' => now()->subDay()]);
    $before = $legacy->refresh()->getRawOriginal();
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->twice();

    $publisher = app(EventPublisher::class);
    $started = $publisher->record($email, ['event_type' => 'submission_started', 'payload' => [], 'occurred_at' => now()]);
    $accepted = $publisher->record($email, ['event_type' => 'submission_accepted', 'payload' => [], 'occurred_at' => now()]);

    expect($started->email_sequence)->toBe(1)
        ->and($accepted->email_sequence)->toBe(2)
        ->and($email->fresh()->event_sequence)->toBe(2)
        ->and($started->contract_version)->toBe(2)
        ->and($started->public_event_data)->toBeArray()
        ->and($legacy->fresh()->getRawOriginal())->toBe($before);
});

it('publishes a late orphan after newer evidence exactly once while preserving its identity', function (): void {
    $email = eventPublicationEmail();
    $orphan = EmailEvent::create(['source_id' => $email->source_id, 'event_type' => 'delivery',
        'payload' => [], 'occurred_at' => now()->subHour()]);
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->times(3);
    $publisher = app(EventPublisher::class);
    $newer = $publisher->record($email, ['event_type' => 'submission_accepted', 'payload' => [], 'occurred_at' => now()]);
    $linked = $publisher->publish($orphan, $email);
    $evidence = $linked->public_event_data;
    $replayed = $publisher->publish($orphan, $email);

    expect($linked->id)->toBe($orphan->id)->toBeLessThan($newer->id)
        ->and($linked->email_sequence)->toBe(2)
        ->and($replayed->email_sequence)->toBe(2)
        ->and($replayed->public_event_data)->toEqual($evidence)
        ->and($email->fresh()->event_sequence)->toBe(2);
});

it('does not attach an event to an email owned by a different source', function (): void {
    $email = eventPublicationEmail();
    $other = eventPublicationEmail();
    $orphan = EmailEvent::create(['source_id' => $other->source_id, 'event_type' => 'delivery',
        'payload' => [], 'occurred_at' => now()]);
    $this->mock(WebhookDeliveryService::class)->shouldNotReceive('dispatchFor');

    expect(fn () => app(EventPublisher::class)->publish($orphan, $email))->toThrow(LogicException::class);
    expect($orphan->fresh()->email_id)->toBeNull()->and($email->fresh()->event_sequence)->toBe(0);
});

it('rolls back both sequence and evidence without dispatching before commit', function (): void {
    $email = eventPublicationEmail();
    $this->mock(WebhookDeliveryService::class)->shouldNotReceive('dispatchFor');
    $count = EmailEvent::query()->count();

    expect(function () use ($email): void {
        DB::transaction(function () use ($email): void {
            app(EventPublisher::class)->record($email, ['event_type' => 'submission_started', 'payload' => [], 'occurred_at' => now()]);
            throw new RuntimeException('Rollback publication');
        });
    })->toThrow(RuntimeException::class, 'Rollback publication');

    expect($email->fresh()->event_sequence)->toBe(0)->and(EmailEvent::query()->count())->toBe($count);
});

it('freezes canonical evidence separately from the mutable email snapshot and sanitizes action URLs', function (): void {
    $email = eventPublicationEmail();
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->twice();
    $event = app(EventPublisher::class)->record($email, [
        'event_type' => 'click', 'provider_event_id' => hash('sha256', 'click'),
        'recipient' => null, 'url' => 'https://name:secret@example.net/reset/private-token?token=secret#secret',
        'payload' => ['mail' => ['destination' => ['first@example.net', 'second@example.net'],
            'headers' => [['name' => 'Authorization', 'value' => 'secret']]],
            'click' => ['timestamp' => '2026-10-30T10:00:00Z', 'link' => 'https://example.net/reset/private-token?token=secret']],
        'occurred_at' => '2026-10-30T10:00:00Z',
    ]);
    $before = app(EventEvidence::class)->for($event);
    $email->update(['status' => 'complained', 'subject' => 'Changed']);
    app(EventPublisher::class)->publish($event, $email);

    expect($before)->toMatchArray(['id' => 'evt_'.$event->id, 'object' => 'email_event', 'type' => 'click',
        'contract_version' => 2, 'email_id' => $email->public_id, 'source_id' => $email->source_id,
        'sequence' => 1, 'origin' => 'ses', 'attempt_id' => null, 'recipient' => null,
        'recipients' => ['first@example.net', 'second@example.net'], 'url' => 'https://example.net'])
        ->and($before['details'])->toMatchArray(['url_redacted' => true, 'recipient_attribution' => 'message'])
        ->and($before['payload'])->toBe($before['details'])
        ->and(json_encode($before))->not->toContain('secret', 'private-token', 'Authorization', 'status', 'subject')
        ->and(app(EventEvidence::class)->for($event->fresh()))->toEqual($before);
});

it('rejects changes to published evidence while leaving legacy rows compatible', function (string $field, mixed $value): void {
    $email = eventPublicationEmail();
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->once();
    $event = app(EventPublisher::class)->record($email, ['event_type' => 'submission_started', 'payload' => [], 'occurred_at' => now()]);

    expect(fn () => $event->forceFill([$field => $value])->save())->toThrow(LogicException::class, 'immutable');
})->with([
    ['event_type', 'send'], ['occurred_at', '2026-01-01T00:00:00Z'], ['payload', ['error' => 'Changed']],
    ['public_event_data', ['type' => 'changed']], ['email_sequence', 99], ['contract_version', null],
]);

it('describes legacy evidence honestly without rewriting its identity or filling missing transitions', function (): void {
    $email = eventPublicationEmail();
    $event = $email->events()->create(['source_id' => $email->source_id, 'event_type' => 'send',
        'payload' => ['MessageId' => 'ses-legacy', 'error' => 'secret'], 'occurred_at' => now()->subDay()]);
    $before = $event->refresh()->getRawOriginal();
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->once();
    app(EventPublisher::class)->publish($event, $email);
    $evidence = app(EventEvidence::class)->for($event->fresh());

    expect($evidence)->toMatchArray(['id' => 'evt_'.$event->id, 'contract_version' => 1,
        'origin' => 'legacy', 'sequence' => null, 'type' => 'send'])
        ->and(json_encode($evidence))->not->toContain('secret', 'submission_started')
        ->and($event->fresh()->getRawOriginal())->toBe($before)
        ->and($email->fresh()->event_sequence)->toBe(0);
});

it('preserves fractional provider occurrence time across publication reload and replay', function (): void {
    $email = eventPublicationEmail();
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->twice();
    $orphan = EmailEvent::create(['source_id' => $email->source_id, 'event_type' => 'delivery_delay',
        'provider_event_id' => hash('sha256', 'delay'), 'occurred_at' => '2026-10-30T10:00:00.123456Z',
        'payload' => ['mail' => ['timestamp' => '2026-10-30T09:59:00.777Z'],
            'deliveryDelay' => ['timestamp' => '2026-10-30T10:00:00.123456Z', 'delayType' => 'MailboxFull',
                'delayedRecipients' => [['emailAddress' => 'recipient@example.net', 'status' => '4.2.2',
                    'diagnosticCode' => 'smtp; mailbox full']], 'expirationTime' => '2026-10-31T10:00:00Z']]]);
    $published = app(EventPublisher::class)->publish($orphan->fresh(), $email);
    $evidence = app(EventEvidence::class)->for($published);
    app(EventPublisher::class)->publish($published->fresh(), $email);

    expect($evidence['occurred_at'])->toBe('2026-10-30T10:00:00.123456Z')
        ->and($evidence['created_at'])->toBe($evidence['occurred_at'])
        ->and($evidence['details'])->toMatchArray(['delay_type' => 'MailboxFull',
            'expiration_time' => '2026-10-31T10:00:00Z', 'recipient_attribution' => 'recipient',
            'recipients' => [['email' => 'recipient@example.net', 'status' => '4.2.2', 'diagnostic_code' => 'smtp; mailbox full']]])
        ->and(app(EventEvidence::class)->for($published->fresh()))->toEqual($evidence);
});

it('keeps sequences scoped to each email and enforces unique submission correlation', function (): void {
    $first = eventPublicationEmail();
    $second = eventPublicationEmail();
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->twice();
    $publisher = app(EventPublisher::class);
    $firstEvent = $publisher->record($first, ['event_type' => 'submission_started', 'payload' => [],
        'occurred_at' => now(), 'submission_correlation_id' => 'opaque-attempt-one']);
    $secondEvent = $publisher->record($second, ['event_type' => 'submission_started', 'payload' => [],
        'occurred_at' => now(), 'submission_correlation_id' => 'opaque-attempt-two']);

    expect(fn () => $publisher->record($second, ['event_type' => 'submission_started', 'payload' => [],
        'occurred_at' => now(), 'submission_correlation_id' => 'opaque-attempt-one']))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($firstEvent->email_sequence)->toBe(1)->and($secondEvent->email_sequence)->toBe(1)
        ->and($first->fresh()->event_sequence)->toBe(1)->and($second->fresh()->event_sequence)->toBe(1);
});

it('returns durable evidence when the after commit webhook broker push or reporting fails', function (bool $reportingFails): void {
    $email = eventPublicationEmail();
    $failure = new RuntimeException('Broker unavailable');
    $handler = $this->mock(ExceptionHandler::class);
    $report = $handler->shouldReceive('report')->with($failure)->once();

    if ($reportingFails) {
        $report->andThrow(new RuntimeException('Reporter unavailable'));
    }

    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->once()->andReturnUsing(function () use ($failure): void {
        expect(DB::transactionLevel())->toBe(1);
        throw $failure;
    });

    $event = app(EventPublisher::class)->record($email, ['event_type' => 'submission_started',
        'payload' => [], 'occurred_at' => now()]);

    expect($email->fresh()->event_sequence)->toBe(1)
        ->and($email->events()->sole()->public_event_data['sequence'])->toBe(1)
        ->and($event->id)->toBe($email->events()->sole()->id);
})->with([false, true]);

it('does not present the first of multiple affected recipients as the sole attributed recipient', function (): void {
    $email = eventPublicationEmail();
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->once();
    $event = app(EventPublisher::class)->record($email, ['event_type' => 'bounce',
        'provider_event_id' => hash('sha256', 'multiple'), 'recipient' => 'first@example.net',
        'payload' => ['bounce' => ['bouncedRecipients' => [['emailAddress' => 'first@example.net'],
            ['emailAddress' => 'second@example.net']]]], 'occurred_at' => now()]);

    expect(app(EventEvidence::class)->for($event)['recipient'])->toBeNull()
        ->and(app(EventEvidence::class)->for($event)['recipients'])->toBe(['first@example.net', 'second@example.net']);
});

it('atomically rejects evidence changes from an instance loaded before publication', function (string $field, mixed $value): void {
    $email = eventPublicationEmail();
    $orphan = EmailEvent::create(['source_id' => $email->source_id, 'event_type' => 'delivery',
        'payload' => [], 'occurred_at' => now()]);
    $stale = $orphan->fresh();
    $this->mock(WebhookDeliveryService::class)->shouldReceive('dispatchFor')->once();
    $published = app(EventPublisher::class)->publish($orphan, $email);
    $before = $published->fresh()->getRawOriginal();

    expect(fn () => $stale->forceFill([$field => $value])->save())->toThrow(LogicException::class, 'immutable');
    expect($published->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['event_type', 'changed'], ['public_event_data', ['type' => 'changed']],
]);
