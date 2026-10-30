<?php

use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SesEventNormalizer;
use App\Services\WebhookDeliveryService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function sesReliabilityFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'SES', 'slug' => 'ses-reliability']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'SES', 'slug' => 'ses-reliability']);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Prod', 'webhook_token' => 'ses-reliability']);
    $email = Email::create(['public_id' => 'email_reliability', 'workspace_id' => $workspace->id, 'project_id' => $project->id,
        'source_id' => $source->id, 'status' => 'sent', 'ses_message_id' => 'ses-reliability-1', 'from_email' => 'hello@example.com', 'subject' => 'Reliable']);
    Queue::fake();

    return [$source, $email];
}

function sesReliabilityPayload(string $type): array
{
    return ['eventType' => $type, 'mail' => ['messageId' => 'ses-reliability-1', 'timestamp' => '2026-10-29T10:00:00Z', 'destination' => ['recipient@example.net']],
        strtolower($type) => ['timestamp' => '2026-10-29T10:01:00Z', 'recipients' => ['recipient@example.net']]];
}

it('deduplicates repeated authenticated sns notifications', function (): void {
    [$source, $email] = sesReliabilityFixture();
    Http::fake([SES_TEST_SIGNING_CERT_URL => Http::response(sesTestPublicCertificate())]);
    $envelope = sesSignedSnsEnvelope('Notification', ['Message' => json_encode(sesReliabilityPayload('Delivery'))]);
    $url = "/api/webhooks/ses/{$source->webhook_token}";
    $this->postJson($url, $envelope)->assertSuccessful();
    $this->postJson($url, $envelope)->assertSuccessful();
    expect($email->events()->count())->toBe(1);
});

it('keeps deduplication source scoped and preserves distinct provider notification ids', function (): void {
    [$source] = sesReliabilityFixture();
    $normalizer = app(SesEventNormalizer::class);
    $normalizer->record($source, sesReliabilityPayload('Open'), 'sns-1');
    $normalizer->record($source, sesReliabilityPayload('Open'), 'sns-1');
    $normalizer->record($source, sesReliabilityPayload('Open'), 'sns-2');
    $other = Source::create(['project_id' => $source->project_id, 'name' => 'Other', 'environment' => 'dev', 'webhook_token' => 'other-ses']);
    $normalizer->record($other, sesReliabilityPayload('Open'), 'sns-1');
    expect(EmailEvent::query()->count())->toBe(3)
        ->and(EmailEvent::query()->where('source_id', $other->id)->sole()->email_id)->toBeNull();
});

it('does not downgrade terminal or engagement status for delayed and unordered events', function (string $current, string $eventType): void {
    [$source, $email] = sesReliabilityFixture();
    $email->forceFill(['status' => $current])->save();
    app(SesEventNormalizer::class)->record($source, sesReliabilityPayload($eventType), 'sns-unordered');
    expect($email->fresh()->status)->toBe($current)
        ->and($email->events()->count())->toBe(1);
})->with([['clicked', 'Open'], ['opened', 'Delivery'], ['delivered', 'Send'], ['complained', 'Delivery'], ['bounced', 'Open'], ['clicked', 'Unknown']]);

it('retries downstream webhook dispatch after the event was committed before dispatch failed', function (): void {
    [$source, $email] = sesReliabilityFixture();
    Exceptions::fake();
    $delivery = Mockery::mock(WebhookDeliveryService::class);
    $delivery->shouldReceive('dispatchFor')->once()->andThrow(new RuntimeException('Queue unavailable'));
    $delivery->shouldReceive('dispatchFor')->once()->withArgs(fn (EmailEvent $event): bool => $event->email_id === $email->id);
    $normalizer = new SesEventNormalizer($delivery);
    $first = $normalizer->record($source, sesReliabilityPayload('Delivery'), 'sns-recovery');
    $second = $normalizer->record($source, sesReliabilityPayload('Delivery'), 'sns-recovery');
    expect($email->events()->count())->toBe(1)->and($email->fresh()->status)->toBe('delivered')
        ->and($second->id)->toBe($first->id)->and($second->public_event_data)->toEqual($first->public_event_data);
});

it('deduplicates fallback payloads independent of object property order', function (): void {
    [$source, $email] = sesReliabilityFixture();
    $payload = sesReliabilityPayload('Delivery');
    app(SesEventNormalizer::class)->record($source, $payload);
    $payload['mail'] = array_reverse($payload['mail'], true);
    app(SesEventNormalizer::class)->record($source, array_reverse($payload, true));
    expect($email->events()->count())->toBe(1);
});

it('relinks an orphaned SES event on authenticated replay without crossing source boundaries', function (): void {
    [$source, $email] = sesReliabilityFixture();
    $email->forceFill(['ses_message_id' => null])->save();
    $normalizer = app(SesEventNormalizer::class);
    $event = $normalizer->record($source, sesReliabilityPayload('Delivery'), 'sns-early');
    expect($event->email_id)->toBeNull();
    $email->forceFill(['ses_message_id' => 'ses-reliability-1'])->save();
    $normalizer->record($source, sesReliabilityPayload('Delivery'), 'sns-early');
    expect($event->fresh()->email_id)->toBe($email->id)->and($email->fresh()->status)->toBe('delivered');
});
