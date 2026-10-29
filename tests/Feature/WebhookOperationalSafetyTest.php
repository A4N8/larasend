<?php

use App\Exceptions\WebhookDnsResolutionException;
use App\Http\Requests\StoreWebhookEndpointRequest;
use App\Jobs\DeliverInboundWebhook;
use App\Jobs\DeliverWebhook;
use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\InboundEmailIngestor;
use App\Services\WebhookDeliveryService;
use App\Services\WebhookUrlGuard;
use App\Support\ProjectContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Connectors\SyncConnector;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

function flakyOperationalQueue(): void
{
    $queue = new class extends SyncQueue
    {
        public int $pushes = 0;

        protected function executeJob($job, $data = '', $queue = null)
        {
            if (++$this->pushes === 1) {
                throw new RuntimeException('Broker temporarily unavailable.');
            }

            return parent::executeJob($job, $data, $queue);
        }
    };
    Queue::extend('flaky-webhook', fn () => new class($queue) extends SyncConnector
    {
        public function __construct(private SyncQueue $queue) {}

        public function connect(array $config): SyncQueue
        {
            return $this->queue;
        }
    });
    config(['queue.default' => 'flaky-webhook', 'queue.connections.flaky-webhook' => ['driver' => 'flaky-webhook']]);
}

function operationalWebhookJob(bool $inbound, string $url = 'https://1.1.1.1/events'): array
{
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());
    $source = $project->sources()->firstOrFail();
    $endpoint = WebhookEndpoint::issue($project, $url, [$inbound ? 'inbound.received' : 'delivery'])['endpoint'];

    if ($inbound) {
        $email = InboundEmail::create([
            'project_id' => $project->id, 'workspace_id' => $project->workspace_id, 'source_id' => $source->id,
            'public_id' => 'inbound_safety', 'from_email' => 'sender@example.com', 'to_email' => 'inbox@example.com',
            'mime_disk' => 'local', 'mime_path' => 'test.eml', 'mime_size' => 4, 'received_at' => now(),
        ]);

        return [new DeliverInboundWebhook($email->id, $endpoint->id), $endpoint, $email];
    }

    $email = Email::create([
        'project_id' => $project->id, 'workspace_id' => $project->workspace_id, 'source_id' => $source->id,
        'public_id' => 'email_safety', 'from_email' => 'sender@example.com', 'status' => 'sent', 'subject' => 'Safety test',
    ]);
    $event = $email->events()->create(['source_id' => $source->id, 'event_type' => 'delivery', 'payload' => [], 'occurred_at' => now()]);

    return [new DeliverWebhook($endpoint->id, $event->id), $endpoint, $email];
}

it('validates webhook destinations when storing endpoints', function () {
    $rules = (new StoreWebhookEndpointRequest)->rules();
    $validator = Validator::make(['url' => 'http://169.254.169.254/latest/meta-data'], ['url' => $rules['url']]);

    expect($validator->fails())->toBeTrue();
});

it('pauses unsafe destinations without sending a request', function (bool $inbound) {
    [$job, $endpoint] = operationalWebhookJob($inbound, 'http://127.0.0.1/private');
    Http::fake();

    app()->call([$job, 'handle']);

    Http::assertNothingSent();
    expect($endpoint->fresh()->status)->toBe('paused')
        ->and(WebhookDelivery::query()->sole()->status)->toBe('fail');
})->with([false, true]);

it('records and retries non-successful responses before marking the final attempt failed', function (bool $inbound, int $status) {
    [$job, $endpoint] = operationalWebhookJob($inbound);
    Http::fake(['*' => Http::response('Unavailable', $status)]);
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(5);
    $job->setJob($queueJob);

    app()->call([$job, 'handle']);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $queueJob->shouldNotReceive('release');
    $job->setJob($queueJob);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class)
        ->and(WebhookDelivery::query()->count())->toBe(2)
        ->and($endpoint->fresh()->last_delivered_at)->toBeNull();
})->with([false, true])->with([302, 429, 503]);

it('retries dns outages without pausing the endpoint', function (bool $inbound) {
    [$job, $endpoint] = operationalWebhookJob($inbound);
    $guard = Mockery::mock(WebhookUrlGuard::class);
    $guard->shouldReceive('requestOptions')->once()->andThrow(new WebhookDnsResolutionException('Temporary DNS outage.'));
    app()->instance(WebhookUrlGuard::class, $guard);
    Http::fake();
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(5);
    $job->setJob($queueJob);

    app()->call([$job, 'handle']);

    Http::assertNothingSent();
    expect($endpoint->fresh()->status)->toBe('active')
        ->and(WebhookDelivery::query()->sole()->http_status)->toBeNull();
})->with([false, true]);

it('records connection errors and exhausts retries', function (bool $inbound) {
    [$job] = operationalWebhookJob($inbound);
    Http::fake(['*' => Http::failedConnection()]);
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $job->setJob($queueJob);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class)
        ->and(WebhookDelivery::query()->sole()->status)->toBe('fail');
})->with([false, true]);

it('does not redeliver a completed logical webhook when an event is replayed', function (bool $inbound) {
    [$job] = operationalWebhookJob($inbound);
    Http::fake(['*' => Http::response('Accepted', 202)]);

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    Http::assertSentCount(1);
    expect(WebhookDelivery::query()->count())->toBe(1);
})->with([false, true]);

it('pins dns and disables redirects and proxies on the actual delivery request', function (bool $inbound) {
    [$job] = operationalWebhookJob($inbound, 'https://hooks.example.com:8443/events');
    app()->instance(WebhookUrlGuard::class, new class extends WebhookUrlGuard
    {
        protected function resolveHost(string $host): array
        {
            return ['1.1.1.1'];
        }
    });
    $requestOptions = [];
    Http::fake(function ($request, array $options) use (&$requestOptions) {
        $requestOptions = $options;

        return Http::response('Accepted', 202);
    });

    app()->call([$job, 'handle']);

    expect($requestOptions['allow_redirects'])->toBeFalse()
        ->and($requestOptions['proxy'])->toBe('')
        ->and($requestOptions['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:8443:1.1.1.1']);
})->with([false, true]);

it('releases unique locks after an actual broker push failure so replay can recover', function (bool $inbound) {
    [$job] = operationalWebhookJob($inbound);
    flakyOperationalQueue();
    Http::fake(['*' => Http::response('Accepted', 202)]);
    $service = app(WebhookDeliveryService::class);

    expect(fn () => $service->dispatchJob($job))->toThrow(RuntimeException::class, 'Broker temporarily unavailable.');

    $service->dispatchJob($job);

    Http::assertSentCount(1);
    expect(WebhookDelivery::query()->sole()->status)->toBe('ok');
})->with([false, true]);

it('retains committed inbound mime when fanout cannot be queued', function () {
    Storage::fake();
    flakyOperationalQueue();
    $project = app(ProjectContext::class)->projectFor(User::factory()->create());
    $source = $project->sources()->firstOrFail();
    $mime = "From: sender@example.com\r\nTo: inbox@example.com\r\nSubject: Durable\r\n\r\nHello";

    expect(fn () => app(InboundEmailIngestor::class)->ingest($source, 'sender@example.com', 'inbox@example.com', $mime))
        ->toThrow(RuntimeException::class, 'Broker temporarily unavailable.');

    $inbound = InboundEmail::query()->sole();
    Storage::disk($inbound->mime_disk)->assertExists($inbound->mime_path);
});

it('fans out independent endpoint jobs on the selected queue and connection', function (?string $queue) {
    [, $endpoint, $inbound] = operationalWebhookJob(true);
    $other = WebhookEndpoint::issue($endpoint->project, 'https://1.0.0.1/events', ['inbound.received'])['endpoint'];
    Queue::fake();
    $job = (new DeliverInboundWebhook($inbound->id))->onQueue($queue)->onConnection('redis');

    app()->call([$job, 'handle']);

    Queue::assertPushed(DeliverInboundWebhook::class, 2);
    foreach ([$endpoint, $other] as $target) {
        Queue::assertPushed(DeliverInboundWebhook::class, fn (DeliverInboundWebhook $delivery) => $delivery->webhookEndpointId === $target->id && $delivery->queue === $queue && $delivery->connection === 'redis');
    }
})->with([null, 'cloud-webhooks']);
