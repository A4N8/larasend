<?php

use App\Http\Requests\StoreWebhookEndpointRequest;
use App\Jobs\DeliverWebhook;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\EventEvidence;
use App\Services\EventPublisher;
use App\Services\WebhookDeliveryService;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;

function outboundContractFixture(): array
{
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());
    $source = $project->sources()->sole();
    $email = $project->emails()->create([
        'public_id' => 'email_'.str()->random(24), 'workspace_id' => $project->workspace_id, 'source_id' => $source->id,
        'status' => 'sent', 'from_email' => 'sender@example.com', 'subject' => 'Evidence contract',
    ]);
    $email->recipients()->create(['type' => 'to', 'email' => 'recipient@example.com']);
    Queue::fake();

    return [$email, $source];
}

it('accepts and actually dispatches all subscribed lifecycle and SES wire types through the common publisher', function (string $type) {
    [$email] = outboundContractFixture();
    $endpoint = WebhookEndpoint::issue($email->project, 'https://1.1.1.1/events', [$type])['endpoint'];
    $rules = (new StoreWebhookEndpointRequest)->rules();
    $validation = Validator::make(['events' => [$type]], ['events' => $rules['events'], 'events.*' => $rules['events.*']]);

    expect($validation->passes())->toBeTrue();
    $event = app(EventPublisher::class)->record($email, ['event_type' => $type, 'payload' => [], 'occurred_at' => now()]);

    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->webhookEndpointId === $endpoint->id && $job->emailEventId === $event->id);
})->with(['submission_started', 'submission_accepted', 'submission_rejected', 'delivery_uncertain', 'failed', 'send', 'reject', 'delivery_delay', 'rendering_failure', 'delivery', 'bounce', 'complaint', 'open', 'click', 'suppress']);

it('filters and delivers by frozen canonical type even if an event row later drifts', function () {
    [$email] = outboundContractFixture();
    $event = app(EventPublisher::class)->record($email, ['event_type' => 'delivery', 'payload' => [], 'occurred_at' => now()]);
    $expected = app(EventEvidence::class)->for($event);
    $issued = WebhookEndpoint::issue($email->project, 'https://1.1.1.1/events', ['delivery']);
    DB::table('email_events')->where('id', $event->id)->update(['event_type' => 'click', 'occurred_at' => now()->addHour()]);
    $event->refresh();

    app(WebhookDeliveryService::class)->dispatchFor($event);
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->emailEventId === $event->id);
    Http::fake(['*' => Http::response('Accepted', 202)]);
    app()->call([new DeliverWebhook($issued['endpoint']->id, $event->id), 'handle']);

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->event_type)->toBe('delivery')->and($delivery->payload['id'])->toBe($expected['id'])
        ->and($delivery->payload['type'])->toBe($expected['type'])->and($delivery->payload['created_at'])->toBe($expected['created_at'])
        ->and($delivery->payload['data']['event'])->toEqual($expected);
    Http::assertSent(fn ($request): bool => $request->hasHeader('Larasend-Event-Type', 'delivery') && $request->hasHeader('Larasend-Event-Id', $expected['id']));
});

it('keeps webhook retries and API history identical for immutable evidence while the email snapshot changes', function () {
    [$email, $source] = outboundContractFixture();
    $event = app(EventPublisher::class)->record($email, [
        'event_type' => 'click', 'provider_event_id' => hash('sha256', 'canonical-click'), 'recipient' => 'recipient@example.com',
        'url' => 'https://example.com/reset/private-action-token?token=secret-query#secret-fragment',
        'payload' => ['mail' => ['destination' => ['recipient@example.com'], 'headers' => [['name' => 'Authorization', 'value' => 'Bearer private-header']]], 'click' => ['link' => 'https://example.com/reset/private-action-token?token=secret-query']],
        'occurred_at' => now(),
    ]);
    $expected = app(EventEvidence::class)->for($event);
    $issued = WebhookEndpoint::issue($email->project, 'https://1.1.1.1/events', ['click']);
    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = ['body' => $request->body(), 'signature' => $request->header('Larasend-Signature')[0]];

        return Http::response('Response', count($requests) === 1 ? 503 : 202);
    });
    $job = unserialize(serialize(new DeliverWebhook($issued['endpoint']->id, $event->id)));
    app()->call([$job, 'handle']);
    $email->update(['status' => 'clicked']);
    $this->travel(1)->seconds();
    app()->call([$job, 'handle']);
    $key = ApiKey::issue($email->project, 'History', $source, ['read:activity']);
    $history = $this->withToken($key['plain_text'])->getJson("/api/emails/{$email->public_id}/events")->assertSuccessful();

    expect($requests)->toHaveCount(2);
    foreach ($requests as $index => $request) {
        $payload = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        $timestamp = str($request['signature'])->between('t=', ',v1=')->toString();
        $signature = hash_hmac('sha256', $timestamp.'.'.$request['body'], $issued['plain_text']);
        expect($payload['data']['event'])->toEqual($expected)->toEqual($history->json('data.0'))
            ->and($payload['data']['email']['status'])->toBe($index === 0 ? 'sent' : 'clicked')
            ->and($request['signature'])->toBe("t={$timestamp},v1={$signature}")
            ->and($request['body'])->not->toContain('private-action-token')->not->toContain('secret-query')->not->toContain('private-header');
    }
});

it('preserves legacy published ids types and current email snapshot fields with a sanitized payload alias', function () {
    [$email, $source] = outboundContractFixture();
    $event = $email->events()->create(['source_id' => $source->id, 'event_type' => 'delivery', 'payload' => ['mail' => ['headers' => ['private-header-token']]], 'occurred_at' => now()]);
    $endpoint = WebhookEndpoint::issue($email->project, 'https://1.1.1.1/events', ['delivery'])['endpoint'];
    Http::fake(['*' => Http::response('Accepted', 202)]);

    app()->call([new DeliverWebhook($endpoint->id, $event->id), 'handle']);

    $payload = WebhookDelivery::query()->sole()->payload;
    expect($payload['id'])->toBe('evt_'.$event->id)->and($payload['type'])->toBe('delivery')
        ->and(array_keys($payload['data']['email']))->toEqualCanonicalizing(['id', 'status', 'subject', 'from', 'to', 'ses_message_id'])
        ->and($payload['data']['event']['contract_version'])->toBe(1)
        ->and($payload['data']['event']['origin'])->toBe('legacy')
        ->and($payload['data']['event']['sequence'])->toBeNull()
        ->and($payload['data']['event']['payload'])->toBe($payload['data']['event']['details'])
        ->and(json_encode($payload))->not->toContain('private-header-token');
});
