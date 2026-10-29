<?php

use App\Exceptions\EmailNotSubmittedException;
use App\Jobs\RecoverStuckQueuedEmails;
use App\Jobs\SendQueuedEmail;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Providers\EmailProviderFactory;
use App\Services\SesEventNormalizer;
use App\Services\SesV2Client;
use App\Services\WebhookDeliveryService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

function deliveryReliabilityFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Reliability', 'slug' => 'reliability']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Reliability', 'slug' => 'reliability']);
    $source = $project->sources()->create([
        'name' => 'Production', 'environment' => 'prod', 'default_from_email' => 'hello@example.com',
        'aws_access_key_id' => 'test-key', 'aws_secret_access_key' => 'test-secret',
        'last_quota' => ['MaxSendRate' => 100], 'last_quota_checked_at' => now(), 'webhook_token' => 'reliability-token',
    ]);
    $project->domains()->create(['domain' => 'example.com', 'status' => 'verified', 'dns_records' => []]);
    $token = ApiKey::issue($project, 'Sender', $source)['plain_text'];
    Storage::fake('local');
    Queue::fake();
    Http::preventStrayRequests();

    return [$project, $source, $token];
}

function deliveryReliabilityPayload(): array
{
    return ['from' => 'hello@example.com', 'to' => ['recipient@example.net'], 'subject' => 'Reliable', 'text' => 'Hello'];
}

it('replays a validated request with reordered object keys without storing or queueing another email', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $payload = deliveryReliabilityPayload() + ['tags' => ['first' => '1', 'second' => '2']];
    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'order-1')->postJson('/api/emails', $payload)->assertAccepted();
    $payload['tags'] = ['second' => '2', 'first' => '1'];
    $this->withToken($token)->postJson('/api/emails', array_reverse($payload, true))->assertAccepted()
        ->assertJsonPath('id', $first->json('id'))->assertJsonPath('replayed', true);
    expect(Email::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('emails'))->toHaveCount(1);
    Queue::assertPushed(SendQueuedEmail::class, 1);
});

it('rejects reuse of an idempotency key with another payload', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->withHeader('Idempotency-Key', 'order-1')->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $this->withToken($token)->postJson('/api/emails', array_replace(deliveryReliabilityPayload(), ['subject' => 'Different']))->assertConflict();
    expect(Email::query()->count())->toBe(1);
});

it('keeps idempotency replays scoped to the authenticated credential', function (): void {
    [$project, $source, $token] = deliveryReliabilityFixture();
    $otherToken = ApiKey::issue($project, 'Another sender', $source)['plain_text'];
    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'shared-key')->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $second = $this->withToken($otherToken)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    expect($second->json('id'))->not->toBe($first->json('id'));
});

it('continues accepting independent requests without an idempotency header', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    expect(Email::query()->count())->toBe(2);
});

it('rejects oversized idempotency headers', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->withHeader('Idempotency-Key', str_repeat('a', 256))->postJson('/api/emails', deliveryReliabilityPayload())->assertUnprocessable();
    expect(Email::query()->count())->toBe(0);
});

it('makes send jobs unique by email id', function (): void {
    $first = new SendQueuedEmail(12);
    expect($first)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($first->uniqueId())->toBe((new SendQueuedEmail(12))->uniqueId())
        ->and($first->uniqueId())->not->toBe((new SendQueuedEmail(13))->uniqueId());
});

it('recovers only old queued emails and never retries ambiguous sending emails', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    Queue::fake();
    (new RecoverStuckQueuedEmails)->handle();
    Queue::assertNothingPushed();
    $email->forceFill(['created_at' => now()->subMinutes(5)])->save();
    (new RecoverStuckQueuedEmails)->handle();
    Queue::assertPushed(SendQueuedEmail::class, fn (SendQueuedEmail $job): bool => $job->emailId === $email->id);
    $email->forceFill(['status' => 'sending'])->save();
    Queue::fake();
    (new RecoverStuckQueuedEmails)->handle();
    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('does not resend or mark failed after an ambiguous provider exception', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('sendRawEmail')->once()->andThrow(new RuntimeException('Connection lost after submission'));
    $this->app->instance(SesV2Client::class, $client);
    $job = new SendQueuedEmail($email->id);
    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(RuntimeException::class);
    expect($email->fresh()->status)->toBe('sending');
    expect($email->events()->where('event_type', 'delivery_uncertain')->sole()->payload['message'])->toContain('before retrying manually');
    $job->handle(app(EmailProviderFactory::class));
    $job->failed(new RuntimeException('Worker stopped'));
    expect($email->fresh()->status)->toBe('sending');
});

it('does not downgrade an email already updated by a provider event when a job fails', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    $email->forceFill(['status' => 'delivered'])->save();
    (new SendQueuedEmail($email->id))->failed(new RuntimeException('Late worker failure'));
    expect($email->fresh()->status)->toBe('delivered');
});

it('releases a unique send lock when queue dispatch fails and preserves committed mime', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
    $this->app->instance(Dispatcher::class, $dispatcher);
    $this->withoutExceptionHandling();
    expect(fn () => $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload()))->toThrow(RuntimeException::class, 'Queue unavailable');
    $email = Email::query()->sole();
    expect($email->status)->toBe('queued');
    Storage::disk($email->mime_disk)->assertExists($email->mime_path);
    expect(Cache::lock(UniqueLock::getKey(new SendQueuedEmail($email->id)), 10)->get())->toBeTrue();
});

it('retries a definitive SES rejection without recording an ambiguous outcome', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    Http::fake(['email.*.amazonaws.com/*' => Http::sequence()->push(['message' => 'Throttled'], 429)->push(['MessageId' => 'ses-retried'])]);
    $job = new SendQueuedEmail($email->id);
    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(RequestException::class);
    expect($email->fresh()->status)->toBe('queued')
        ->and($email->events()->where('event_type', 'delivery_uncertain')->exists())->toBeFalse();
    $job->handle(app(EmailProviderFactory::class));
    expect($email->fresh()->status)->toBe('sent')->and($email->fresh()->ses_message_id)->toBe('ses-retried');
    Http::assertSentCount(2);
});

it('keeps missing mime safely retryable until retries are exhausted', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    Storage::disk($email->mime_disk)->delete($email->mime_path);
    $job = new SendQueuedEmail($email->id);
    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(UnableToReadFile::class);
    expect($email->fresh()->status)->toBe('queued');
    $job->failed(new RuntimeException('MIME unavailable after retries'));
    expect($email->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});

it('does not downgrade engagement observed while provider send is completing', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('sendRawEmail')->once()->andReturnUsing(function () use ($email): array {
        $email->forceFill(['status' => 'clicked'])->save();

        return ['message_id' => 'ses-race', 'response' => ['MessageId' => 'ses-race']];
    });
    $this->app->instance(SesV2Client::class, $client);
    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));
    expect($email->fresh()->status)->toBe('clicked')->and($email->fresh()->ses_message_id)->toBe('ses-race');
});

it('does not send archived project emails', function (): void {
    [$project, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    $project->forceFill(['archived_at' => now()])->save();
    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));
    expect($email->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});

it('keeps SES signing failures retryable because no message was submitted', function (): void {
    [, $source, $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    $source->forceFill(['aws_access_key_id' => null, 'aws_secret_access_key' => null])->save();
    expect(fn () => (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class)))->toThrow(EmailNotSubmittedException::class);
    expect($email->fresh()->status)->toBe('queued');
    Http::assertNothingSent();
});

it('recovers early SES events and downstream dispatch when the SES id is saved after the event', function (): void {
    [, $source, $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('sendRawEmail')->once()->andReturnUsing(function () use ($source, $email): array {
        expect($email->events()->where('event_type', 'submission_started')->sole()->payload['message'])->toContain('provider');
        app(SesEventNormalizer::class)->record($source, [
            'eventType' => 'Click', 'mail' => ['messageId' => 'ses-early-1', 'destination' => ['recipient@example.net']],
            'click' => ['timestamp' => now()->toIso8601String(), 'link' => 'https://example.net'],
        ], 'sns-early-1');

        return ['message_id' => 'ses-early-1', 'response' => ['MessageId' => 'ses-early-1']];
    });
    $this->app->instance(SesV2Client::class, $client);
    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));
    expect($email->fresh()->status)->toBe('clicked')
        ->and($email->events()->where('event_type', 'click')->count())->toBe(1)
        ->and($email->events()->where('event_type', 'send')->count())->toBe(1);
});

it('bounds S3 transport attempts within the worker visibility budget without changing disk selection', function (): void {
    $filesystems = require base_path('config/filesystems.php');
    expect($filesystems['disks']['s3']['http']['connect_timeout'])->toBe(5.0)
        ->and($filesystems['disks']['s3']['http']['timeout'])->toBe(30.0)
        ->and($filesystems['disks']['s3']['retries'])->toBe(1)
        ->and((new SendQueuedEmail(1))->timeout)->toBeLessThan(180);
});

it('does not fan out the local send marker as another SES provider notification', function (): void {
    [, , $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    $delivery = Mockery::mock(WebhookDeliveryService::class);
    $delivery->shouldNotReceive('dispatchFor');
    $this->app->instance(WebhookDeliveryService::class, $delivery);
    Http::fake(['email.*.amazonaws.com/*' => Http::response(['MessageId' => 'ses-local-marker'])]);
    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));
    expect($email->events()->where('event_type', 'send')->count())->toBe(1);
});

it('retries downstream dispatch after send-result commit without submitting the message again', function (): void {
    [, $source, $token] = deliveryReliabilityFixture();
    $this->withToken($token)->postJson('/api/emails', deliveryReliabilityPayload())->assertAccepted();
    $email = Email::query()->sole();
    app(SesEventNormalizer::class)->record($source, [
        'eventType' => 'Click', 'mail' => ['messageId' => 'ses-committed-1', 'destination' => ['recipient@example.net']],
        'click' => ['timestamp' => now()->toIso8601String(), 'link' => 'https://example.net'],
    ], 'sns-committed-1');
    $client = Mockery::mock(SesV2Client::class);
    $client->shouldReceive('sendRawEmail')->once()->andReturn(['message_id' => 'ses-committed-1', 'response' => ['MessageId' => 'ses-committed-1']]);
    $this->app->instance(SesV2Client::class, $client);
    $delivery = Mockery::mock(WebhookDeliveryService::class);
    $delivery->shouldReceive('dispatchFor')->once()->andThrow(new RuntimeException('Queue unavailable after send'));
    $delivery->shouldReceive('dispatchFor')->once();
    $this->app->instance(WebhookDeliveryService::class, $delivery);
    $job = new SendQueuedEmail($email->id);
    expect(fn () => $job->handle(app(EmailProviderFactory::class)))->toThrow(RuntimeException::class, 'Queue unavailable after send');
    expect($email->fresh()->status)->toBe('clicked');
    $job->handle(app(EmailProviderFactory::class));
    expect($email->events()->where('event_type', 'send')->count())->toBe(1);
});

it('releases the unique lock when a deferred dispatch fails after the outer transaction commits', function (): void {
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Deferred queue failure'));
    $this->app->instance(Dispatcher::class, $dispatcher);
    DB::beginTransaction();

    try {
        SendQueuedEmail::dispatchAfterCommit(123);
        $dispatcher->shouldNotHaveReceived('dispatch');
        $lock = Cache::lock(UniqueLock::getKey(new SendQueuedEmail(123)), 10);
        expect($lock->get())->toBeTrue();
        $lock->release();
        expect(fn () => DB::commit())->toThrow(RuntimeException::class, 'Deferred queue failure');
        expect($lock->get())->toBeTrue();
    } finally {
        while (DB::transactionLevel() > 1) {
            DB::rollBack();
        }
    }
});

it('does not acquire a unique lock or dispatch when the outer transaction rolls back', function (): void {
    Queue::fake();
    DB::beginTransaction();
    SendQueuedEmail::dispatchAfterCommit(123);
    DB::rollBack();
    Queue::assertNothingPushed();
    expect(Cache::lock(UniqueLock::getKey(new SendQueuedEmail(123)), 10)->get())->toBeTrue();
});

it('keeps built in queue retry visibility longer than the send worker timeout', function (): void {
    $queues = require base_path('config/queue.php');

    foreach (['database', 'redis', 'beanstalkd'] as $connection) {
        expect($queues['connections'][$connection]['retry_after'])->toBeGreaterThan((new SendQueuedEmail(1))->timeout);
    }
});
