<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\SendQueuedEmail;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\Suppression;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EmailInlineImageResolver;
use App\Services\Providers\EmailProviderFactory;
use App\Services\SesV2Client;
use App\Services\ThreadResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ZBateson\MailMimeParser\MailMimeParser;

it('does not pass internal MIME parameters from API attachment extras to the builder', function (mixed $parameters): void {
    [, , , $token] = larasendProjectFixture();
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    $response = $this->withToken($token)->postJson('/api/emails', [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Internal MIME field isolation', 'text' => 'Hello',
        'attachments' => [['filename' => 'names.csv', 'content' => base64_encode('fictional-data'), 'content_type' => 'text/csv', 'content_type_parameters' => $parameters]],
    ]);
    $response->assertAccepted();
    $email = Email::query()->where('public_id', $response->json('id'))->sole();
    $mime = app(MailMimeParser::class)->parse(Storage::disk($email->mime_disk)->get($email->mime_path), false);
    expect($mime->getAllAttachmentParts()[0]->getHeaderParameter('Content-Type', 'charset'))->toBeNull();
    Queue::assertPushed(SendQueuedEmail::class, 1);
    Http::assertNothingSent();
})->with(['invalid type' => ['invalid-internal-value'], 'invalid nested type' => [['charset' => ['invalid']]], 'untrusted charset' => [['charset' => 'ISO-8859-1']]]);

it('preserves an attachment consisting of the single ASCII zero byte through API MIME and metadata', function (): void {
    [, , , $token] = larasendProjectFixture();
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    $response = $this->withToken($token)->postJson('/api/emails', [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Single zero byte', 'text' => 'Hello',
        'attachments' => [['filename' => 'zero.txt', 'content' => base64_encode('0'), 'content_type' => 'text/plain']],
    ]);
    $response->assertAccepted();
    $email = Email::query()->where('public_id', $response->json('id'))->sole();
    $mime = app(MailMimeParser::class)->parse(Storage::disk($email->mime_disk)->get($email->mime_path), false);
    expect($mime->getAllAttachmentParts()[0]->getBinaryContentStream()?->getContents())->toBe('0')
        ->and((int) $email->attachments()->sole()->size)->toBe(1);
    Queue::assertPushed(SendQueuedEmail::class, 1);
    Http::assertNothingSent();
});

function larasendProjectFixture(string $suffix = ''): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme', 'slug' => 'acme'.$suffix]);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'harborlight', 'slug' => 'harborlight'.$suffix]);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'default_from_email' => 'receipts@example.com',
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'last_quota' => [
            'Max24HourSend' => 50000,
            'MaxSendRate' => 200,
            'SentLast24Hours' => 25,
        ],
        'last_quota_checked_at' => now(),
        'webhook_token' => 'token-'.str()->random(8),
    ]);
    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);
    $issued = ApiKey::issue($project, 'Test key', $source);

    return [$workspace, $project, $source, $issued['plain_text']];
}

it('sends an email with api key auth and stores searchable content', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();

    $response = $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Welcome to Larasend',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
        'headers' => ['X-Test' => 'yes'],
        'tags' => ['kind' => 'welcome'],
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('object', 'email');

    $email = Email::query()->firstOrFail();

    expect($email->workspace_id)->toBe($workspace->id)
        ->and($email->project_id)->toBe($project->id)
        ->and($email->source_id)->toBe($source->id)
        ->and($email->status)->toBe('queued')
        ->and($email->ses_message_id)->toBeNull()
        ->and($email->recipients()->where('email', 'maya@example.com')->exists())->toBeTrue()
        ->and($email->events()->where('event_type', 'send')->exists())->toBeFalse();

    Queue::assertPushed(SendQueuedEmail::class, fn (SendQueuedEmail $job) => $job->emailId === $email->id);
});

it('stores outbound mime on the configured default disk', function () {
    [, , , $token] = larasendProjectFixture();

    config(['filesystems.default' => 'cloud-test']);
    Storage::fake('cloud-test');
    Queue::fake();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Durable storage',
        'text' => 'Stored outside the app instance.',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();

    expect($email->mime_disk)->toBe('cloud-test');
    Storage::disk('cloud-test')->assertExists($email->mime_path);

    $this->app->bind(SesV2Client::class, fn () => new class extends SesV2Client
    {
        public function sendRawEmail(Source $source, string $mime, array $destination = []): array
        {
            expect($mime)->toContain('Durable storage');

            return ['message_id' => 'ses-durable-1', 'response' => ['MessageId' => 'ses-durable-1']];
        }
    });

    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));

    expect($email->fresh()->status)->toBe('sent');
});

it('does not queue or persist outbound email when mime storage fails', function () {
    [, , , $token] = larasendProjectFixture();

    Queue::fake();
    Storage::shouldReceive('getDefaultDriver')->andReturn('failing');
    Storage::shouldReceive('disk')->with('failing')->andReturnSelf();
    Storage::shouldReceive('put')->andReturnFalse();

    $this->withoutExceptionHandling();

    expect(fn () => $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Storage unavailable',
        'text' => 'This must not be queued.',
    ]))->toThrow(RuntimeException::class, 'Unable to store outbound MIME content.');

    expect(Email::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rate limits each api key independently', function () {
    [, $project, $source, $token] = larasendProjectFixture();
    $secondToken = ApiKey::issue($project, 'Second key', $source)['plain_text'];

    config(['larasend.api_rate_limit_per_minute' => 2]);

    $this->withToken($token)->getJson('/api/emails')->assertSuccessful();
    $this->withToken($token)->getJson('/api/emails')->assertSuccessful();
    $this->withToken($token)->getJson('/api/emails')->assertTooManyRequests();

    $this->withToken($secondToken)->getJson('/api/emails')->assertSuccessful();
});

it('rate limits unauthenticated api attempts before authentication', function () {
    config(['larasend.api_auth_rate_limit_per_minute' => 2]);

    $this->getJson('/api/emails')->assertUnauthorized();
    $this->getJson('/api/emails')->assertUnauthorized();
    $this->getJson('/api/emails')->assertTooManyRequests();
});

it('deletes outbound mime when downstream persistence fails', function () {
    [, , , $token] = larasendProjectFixture();

    config(['filesystems.default' => 'cloud-test']);
    Storage::fake('cloud-test');
    Queue::fake();

    $this->mock(ThreadResolver::class)
        ->shouldReceive('attachOutbound')
        ->once()
        ->andThrow(new RuntimeException('Thread persistence failed.'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Cleanup storage',
        'text' => 'Delete this if persistence fails.',
    ]))->toThrow(RuntimeException::class, 'Thread persistence failed.');

    Storage::disk('cloud-test')->assertDirectoryEmpty('/');
    expect(Email::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('archives exact inline image content ids alongside an ordinary PDF attachment', function (string $disk): void {
    [$workspace, $project, , $token] = larasendProjectFixture();
    Queue::fake();
    config(['filesystems.default' => $disk]);
    Storage::fake($disk);
    Http::preventStrayRequests();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $logoCid = 'logo-'.hash('sha256', $png).'@email.local';
    $artworkCid = 'artwork-'.hash('sha256', $png).'@email.local';
    $html = '<p>Frozen email</p><img src="cid:'.$logoCid.'"><img src="cid:'.$artworkCid.'">';

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Inline artwork', 'html' => $html,
        'attachments' => [
            ['filename' => 'logo.png', 'content' => base64_encode($png), 'content_type' => 'image/png', 'content_id' => $logoCid],
            ['filename' => 'artwork.png', 'content' => base64_encode($png), 'content_type' => 'image/png', 'content_id' => $artworkCid],
            ['filename' => 'invoice.pdf', 'content' => base64_encode('pdf-bytes'), 'content_type' => 'application/pdf'],
            ['filename' => 'unused.png', 'content' => base64_encode($png), 'content_type' => 'image/png', 'content_id' => 'unused@email.local'],
        ],
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();
    $mime = Storage::disk($email->mime_disk)->get($email->mime_path);
    $parsed = app(MailMimeParser::class)->parse($mime, autoClose: true);
    $parts = collect($parsed->getAllAttachmentParts())->keyBy(fn ($part) => $part->getFilename());

    expect($email->mime_disk)->toBe($disk)
        ->and($email->html)->toBe($html)
        ->and($mime)->toContain('multipart/related', 'Content-ID: <'.$logoCid.'>', 'Content-ID: <'.$artworkCid.'>')
        ->and($parsed->getHtmlContent())->toBe($html)
        ->and($parts)->toHaveCount(4)
        ->and($parts['logo.png']->getContentId())->toBe($logoCid)
        ->and($parts['artwork.png']->getContentId())->toBe($artworkCid)
        ->and($parts['logo.png']->getContent())->toBe($png)
        ->and($parts['artwork.png']->getContent())->toBe($png)
        ->and($parts['logo.png']->getContentDisposition())->toBe('inline')
        ->and($parts['artwork.png']->getContentDisposition())->toBe('inline')
        ->and($parts['invoice.pdf']->getContentId())->toBeNull()
        ->and($parts['invoice.pdf']->getContentDisposition())->toBe('attachment')
        ->and($parts['invoice.pdf']->getContent())->toBe('pdf-bytes')
        ->and($email->attachments()->pluck('disposition', 'filename')->all())->toBe([
            'logo.png' => 'inline', 'artwork.png' => 'inline', 'invoice.pdf' => 'attachment', 'unused.png' => 'inline',
        ]);
    $this->withToken($token)->getJson('/api/emails/'.$email->public_id)
        ->assertSuccessful()->assertJsonPath('data.html', $html);
    $owner = User::query()->findOrFail($workspace->owner_id);
    $imageSource = 'data:image/png;base64,'.base64_encode($png);
    $this->actingAs($owner)->get(route('emails.preview', $email))
        ->assertSuccessful()->assertSee($imageSource, escape: false)->assertSee('sandbox', escape: false);
    $this->get('/projects/'.$project->slug.'/activity?email='.$email->public_id)->assertInertia(fn ($page) => $page
        ->where('selectedEmail.html', fn (string $preview): bool => str_contains($preview, $imageSource)));
    $this->get('/projects/'.$project->slug.'/inbox?thread='.$email->thread->public_id)->assertInertia(fn ($page) => $page
        ->where('selectedThread.messages.0.html', fn (string $preview): bool => str_contains($preview, $imageSource))
        ->has('selectedThread.messages.0.attachments', 4)
        ->where('selectedThread.messages.0.attachments.2.filename', 'invoice.pdf')
        ->where('selectedThread.messages.0.attachments.3.filename', 'unused.png'));
    expect($email->fresh()->html)->toBe($html)->and(Storage::disk($disk)->get($email->mime_path))->toBe($mime);
    $this->mock(EmailInlineImageResolver::class)->shouldNotReceive('html');
    $this->actingAs(User::factory()->create())->get(route('emails.preview', $email))->assertNotFound();
    Queue::assertPushed(SendQueuedEmail::class, 1);
    Http::assertNothingSent();
})->with(['local', 's3']);

it('reads MIME only for an authorized selected activity email and skips list or unrelated partial reloads', function (): void {
    [$workspace, $project, , $token] = larasendProjectFixture();
    [, , , $foreignToken] = larasendProjectFixture('-foreign');
    Queue::fake();
    $disk = Storage::fake('local');
    Http::preventStrayRequests();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $payload = ['from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Inline preview', 'html' => '<img src="cid:logo@email.local">',
        'attachments' => [['filename' => 'logo.png', 'content' => base64_encode($png), 'content_type' => 'image/png', 'content_id' => 'logo@email.local']]];
    foreach (range(1, 51) as $number) {
        $this->withToken($token)->postJson('/api/emails', [...$payload, 'subject' => 'Inline '.$number])->assertAccepted();
    }
    $foreignId = $this->withToken($foreignToken)->postJson('/api/emails', $payload)->assertAccepted()->json('id');
    $selected = $project->emails()->where('subject', 'Inline 1')->firstOrFail();
    $selected->forceFill(['created_at' => now()->subMinute()])->save();
    $reads = [];
    $spy = Mockery::mock(FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
    $spy->shouldReceive('readStream')->andReturnUsing(function (string $path) use ($disk, &$reads) {
        $reads[] = $path;

        return $disk->readStream($path);
    });
    Storage::set('local', $spy);
    $owner = User::query()->findOrFail($workspace->owner_id);
    $url = '/projects/'.$project->slug.'/activity';

    $this->actingAs($owner)->get($url)->assertInertia(fn ($page) => $page->has('emails', 50)->where('selectedEmail', null));
    expect($reads)->toBe([]);
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '', 'X-Inertia-Partial-Component' => 'Activity', 'X-Inertia-Partial-Data' => 'selectedEmail'])
        ->get($url.'?email='.$selected->public_id)->assertSuccessful()
        ->assertJsonPath('component', 'Activity')
        ->assertJsonPath('props.selectedEmail.id', $selected->public_id)
        ->assertJsonPath('props.selectedEmail.html', fn (string $html): bool => str_contains($html, 'data:image/png;base64,'));
    expect($reads)->toBe([$selected->mime_path]);
    $this->withHeader('X-Inertia-Partial-Data', 'emails')->get($url.'?email='.$selected->public_id)
        ->assertSuccessful()->assertJsonCount(50, 'props.emails')->assertJsonMissingPath('props.selectedEmail');
    expect($reads)->toBe([$selected->mime_path]);
    $this->withHeader('X-Inertia-Partial-Data', 'selectedEmail')->get($url.'?email='.$foreignId)
        ->assertSuccessful()->assertJsonPath('props.selectedEmail', null);
    $this->get($url.'?email='.$selected->public_id.'&q=unmatched-filter')
        ->assertSuccessful()->assertJsonPath('props.selectedEmail', null);
    expect($reads)->toBe([$selected->mime_path]);
    Http::assertNothingSent();
});

it('omits unchanged inbox bodies from polls and reads only new or changed archived messages', function (): void {
    [$workspace, $project, , $token] = larasendProjectFixture();
    Queue::fake();
    $disk = Storage::fake('s3');
    config(['filesystems.default' => 's3']);
    Http::preventStrayRequests();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $payload = ['from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Shared thread', 'html' => '<img src="cid:logo@email.local">',
        'attachments' => [['filename' => 'logo.png', 'content' => base64_encode($png), 'content_type' => 'image/png', 'content_id' => 'logo@email.local']]];
    foreach (range(1, 2) as $number) {
        $this->withToken($token)->postJson('/api/emails', $payload)->assertAccepted();
    }
    $thread = $project->threads()->firstOrFail();
    $reads = [];
    $spy = Mockery::mock(FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
    $spy->shouldReceive('readStream')->andReturnUsing(function (string $path) use ($disk, &$reads) {
        $reads[] = $path;

        return $disk->readStream($path);
    });
    Storage::set('s3', $spy);
    $url = '/projects/'.$project->slug.'/inbox?thread='.$thread->public_id;
    $owner = User::query()->findOrFail($workspace->owner_id);
    $initial = $this->actingAs($owner)->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()) ?? '')
        ->withHeader('X-Inertia', 'true')->get($url)->assertSuccessful()->json('props.selectedThread');
    expect($reads)->toHaveCount(2)->and($initial['body_scope'] ?? null)->toBeString();
    $known = collect($initial['messages'])->pluck('body_fingerprint', 'id')->all();
    $headers = ['X-Inertia-Partial-Component' => 'Inbox', 'X-Inertia-Partial-Data' => 'selectedThread',
        'X-Inbox-Body-Scope' => $initial['body_scope'], 'X-Inbox-Body-Version' => $initial['body_version'], 'X-Inbox-Known-Bodies' => json_encode($known)];

    $this->withHeaders($headers)->get($url)->assertSuccessful()
        ->assertJsonMissingPath('props.selectedThread.messages.0.html')->assertJsonMissingPath('props.selectedThread.messages.0.text');
    expect($reads)->toHaveCount(2);
    $this->withHeader('X-Inbox-Known-Bodies', '{}')->get($url)->assertSuccessful()->assertJsonMissingPath('props.selectedThread.messages.1.html');
    expect($reads)->toHaveCount(2);
    $this->withHeader('X-Inbox-Known-Bodies', json_encode($known));
    $first = $thread->emails()->oldest('id')->firstOrFail();
    $first->forceFill(['status' => 'delivered'])->save();
    $this->get($url)->assertSuccessful()->assertJsonPath('props.selectedThread.messages.0.status', 'delivered')
        ->assertJsonMissingPath('props.selectedThread.messages.0.html');
    expect($reads)->toHaveCount(2);

    $this->withToken($token)->postJson('/api/emails', $payload)->assertAccepted();
    $new = $thread->emails()->latest('id')->firstOrFail();
    $new->forceFill(['created_at' => now()->addSecond()])->save();
    $this->get($url)->assertSuccessful()->assertJsonCount(3, 'props.selectedThread.messages')
        ->assertJsonMissingPath('props.selectedThread.messages.0.html')
        ->assertJsonPath('props.selectedThread.messages.2.html', fn (string $html): bool => str_contains($html, 'data:image/png;base64,'));
    expect($reads)->toHaveCount(3)->and($reads[2])->toBe($new->mime_path);

    $first->forceFill(['html' => $first->html.'<p>Changed body</p>'])->save();
    $this->get($url)->assertSuccessful()->assertJsonPath('props.selectedThread.messages.0.html', fn (string $html): bool => str_contains($html, 'Changed body'));
    expect(array_count_values($reads)[$first->mime_path])->toBe(2);

    foreach (['not-json', str_repeat('x', 4097), json_encode(array_fill_keys(range(1, 26), str_repeat('a', 64))), json_encode([$first->public_id => 'invalid'])] as $invalid) {
        $this->withHeaders(['X-Inbox-Known-Bodies' => $invalid, 'X-Inbox-Body-Version' => 'invalid'])->get($url)
            ->assertSuccessful()->assertJsonPath('props.selectedThread.messages.0.html', fn (string $html): bool => str_contains($html, 'data:image/png;base64,'));
    }

    $this->withHeaders(['X-Inbox-Body-Scope' => str_repeat('a', 64), 'X-Inbox-Body-Version' => $initial['body_version'], 'X-Inbox-Known-Bodies' => json_encode($known)])
        ->get($url)->assertSuccessful()->assertJsonPath('props.selectedThread.messages.1.html', fn (string $html): bool => str_contains($html, 'data:image/png;base64,'));
    $this->mock(EmailInlineImageResolver::class)->shouldNotReceive('html');
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    Http::assertNothingSent();
});

it('retries only transiently failed inline previews while retaining healthy bodies beyond the known ID bound', function (bool $throw): void {
    [$workspace, $project, , $token] = larasendProjectFixture();
    Queue::fake();
    $disk = Storage::fake('s3');
    config(['filesystems.default' => 's3']);
    Http::preventStrayRequests();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $payload = ['from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Transient preview', 'html' => '<img src="cid:logo@email.local">',
        'attachments' => [['filename' => 'logo.png', 'content' => base64_encode($png), 'content_type' => 'image/png', 'content_id' => 'logo@email.local']]];
    foreach (range(1, 31) as $number) {
        $this->withToken($token)->postJson('/api/emails', $payload)->assertAccepted();
    }
    $reads = 0;
    $spy = Mockery::mock(FilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
    $spy->shouldReceive('readStream')->andReturnUsing(function (string $path) use ($disk, &$reads, $throw) {
        if (++$reads === 1) {
            if ($throw) {
                throw new RuntimeException('Transient fake storage timeout');
            }

            return false;
        }

        return $disk->readStream($path);
    });
    Storage::set('s3', $spy);
    $url = '/projects/'.$project->slug.'/inbox?thread='.$project->threads()->firstOrFail()->public_id;
    $initial = $this->actingAs(User::query()->findOrFail($workspace->owner_id))->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()) ?? '')
        ->withHeader('X-Inertia', 'true')
        ->get($url)->assertSuccessful()->json('props.selectedThread');
    expect($reads)->toBe(31)->and($initial['messages'][0]['html'])->toBe($payload['html'])
        ->and($initial['messages'][0]['body_retryable'] ?? false)->toBeTrue();
    $failedId = $initial['messages'][0]['id'];
    $known = collect($initial['messages'])->skip(1)->take(25)->pluck('body_fingerprint', 'id')->all();
    $headers = ['X-Inertia-Partial-Component' => 'Inbox', 'X-Inertia-Partial-Data' => 'selectedThread',
        'X-Inbox-Body-Scope' => $initial['body_scope'], 'X-Inbox-Body-Version' => $initial['body_version'],
        'X-Inbox-Known-Bodies' => json_encode($known), 'X-Inbox-Retry-Bodies' => json_encode([$failedId])];
    $this->withHeaders($headers)->get($url)->assertSuccessful()
        ->assertJsonPath('props.selectedThread.messages.0.html', fn (string $html): bool => str_contains($html, 'data:image/png;base64,'))
        ->assertJsonPath('props.selectedThread.messages.0.body_retryable', false)
        ->assertJsonMissingPath('props.selectedThread.messages.30.html');
    expect($reads)->toBe(32);
    $this->withHeader('X-Inbox-Retry-Bodies', '[]')->get($url)->assertSuccessful()->assertJsonMissingPath('props.selectedThread.messages.0.html');
    expect($reads)->toBe(32);
    $this->withHeader('X-Inbox-Retry-Bodies', json_encode(array_fill(0, 26, $failedId)))->get($url)->assertSuccessful()
        ->assertJsonPath('props.selectedThread.messages.0.html', fn (string $html): bool => str_contains($html, 'data:image/png;base64,'));
    expect($reads)->toBe(63);
    Http::assertNothingSent();
})->with(['exception' => true, 'missing stream' => false]);

it('preserves ordinary attachment behavior when content ids are missing or null', function (): void {
    [, , , $token] = larasendProjectFixture();
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Ordinary attachments', 'text' => 'Documents',
        'attachments' => [
            ['filename' => 'first.pdf', 'content' => base64_encode('first'), 'content_type' => 'application/pdf'],
            ['filename' => 'second.pdf', 'content' => base64_encode('second'), 'content_type' => 'application/pdf', 'content_id' => null],
        ],
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();
    $parsed = app(MailMimeParser::class)->parse(Storage::disk('local')->get($email->mime_path), autoClose: true);
    expect($parsed->getAllAttachmentParts())->toHaveCount(2);
    foreach ($parsed->getAllAttachmentParts() as $part) {
        expect($part->getContentDisposition())->toBe('attachment')->and($part->getContentId())->toBeNull();
    }
    expect($email->attachments()->pluck('disposition')->all())->toBe(['attachment', 'attachment']);
    Queue::assertPushed(SendQueuedEmail::class, 1);
    Http::assertNothingSent();
});

it('rejects malformed inline content ids before storing MIME or queuing mail', function (mixed $cid): void {
    [, , , $token] = larasendProjectFixture();
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Invalid image', 'html' => '<p>Message</p>',
        'attachments' => [['filename' => 'logo.png', 'content' => base64_encode('png-bytes'), 'content_type' => 'image/png', 'content_id' => $cid]],
    ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0.content_id');

    expect(Email::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'missing at sign' => 'logo',
    'angle brackets' => '<logo@email.local>',
    'header injection' => "logo@email.local\r\nX-Injected: yes",
    'trailing CRLF' => "logo@email.local\r\n",
    'leading whitespace' => ' logo@email.local',
    'trailing whitespace' => 'logo@email.local ',
    'URL' => 'https://example.com/logo',
    'too long' => str_repeat('a', 116).'@example.test',
    'non-string' => 42,
    'array' => [['logo@email.local']],
    'invalid local part' => 'logo..name@email.local',
    'invalid domain' => 'logo@email..local',
]);

it('rejects duplicate inline content ids before storing MIME or queuing mail', function (): void {
    [, , , $token] = larasendProjectFixture();
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    $attachment = ['filename' => 'logo.png', 'content' => base64_encode('png-bytes'), 'content_type' => 'image/png', 'content_id' => 'logo@email.local'];

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Duplicate image', 'html' => '<p>Message</p>',
        'attachments' => [$attachment, [...$attachment, 'filename' => 'artwork.png']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['attachments.0.content_id', 'attachments.1.content_id']);

    expect(Email::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('retains exact content id case and the 128 character boundary', function (): void {
    [, , , $token] = larasendProjectFixture();
    Queue::fake();
    Storage::fake('local');
    Http::preventStrayRequests();
    $cid = str_repeat('A', 115).'@example.test';

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'], 'subject' => 'Boundary image', 'html' => '<img src="cid:'.$cid.'">',
        'attachments' => [['filename' => 'logo.png', 'content' => base64_encode('png-bytes'), 'content_type' => 'image/png', 'content_id' => $cid]],
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();
    $parsed = app(MailMimeParser::class)->parse(Storage::disk('local')->get($email->mime_path), autoClose: true);
    expect($parsed->getAttachmentPart(0)->getContentId())->toBe($cid);
    Queue::assertPushed(SendQueuedEmail::class, 1);
    Http::assertNothingSent();
});

it('refreshes stale ses quota before accepting api sends', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();
    $source->forceFill([
        'last_quota' => null,
        'last_quota_checked_at' => now()->subHours(7),
    ])->save();

    Queue::fake();

    $this->app->bind(SesV2Client::class, fn () => new class extends SesV2Client
    {
        public function getAccount(Source $source): array
        {
            return [
                'SendQuota' => [
                    'Max24HourSend' => 50000,
                    'MaxSendRate' => 200,
                    'SentLast24Hours' => 25,
                ],
            ];
        }
    });

    $response = $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Quota refreshed',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ]);

    $response->assertAccepted();

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($source->fresh()->last_quota['max_24_hour_send'])->toBe(50000)
        ->and(Email::query()->where('subject', 'Quota refreshed')->exists())->toBeTrue();

    Queue::assertPushed(SendQueuedEmail::class);
});

it('accepts api sends when ses quota refresh fails', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();
    $source->forceFill([
        'last_quota' => null,
        'last_quota_checked_at' => null,
    ])->save();

    Queue::fake();

    $this->app->bind(SesV2Client::class, fn () => new class extends SesV2Client
    {
        public function getAccount(Source $source): array
        {
            throw new RuntimeException('SES is temporarily unavailable.');
        }
    });

    $response = $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Quota refresh failed',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ]);

    $response->assertAccepted();

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($source->fresh()->last_quota)->toBeNull()
        ->and(Email::query()->where('subject', 'Quota refresh failed')->exists())->toBeTrue();

    Queue::assertPushed(SendQueuedEmail::class);
});

it('sends queued email jobs through ses and records the response', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Welcome to Larasend',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();

    $this->app->bind(SesV2Client::class, fn () => new class extends SesV2Client
    {
        public function sendRawEmail(Source $source, string $mime, array $destination = []): array
        {
            expect($mime)->toContain('Welcome to Larasend');

            return ['message_id' => 'ses-message-1', 'response' => ['MessageId' => 'ses-message-1']];
        }
    });

    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));

    expect($email->fresh())
        ->workspace_id->toBe($workspace->id)
        ->project_id->toBe($project->id)
        ->source_id->toBe($source->id)
        ->status->toBe('sent')
        ->ses_message_id->toBe('ses-message-1')
        ->and($email->events()->where('event_type', 'send')->exists())->toBeTrue();
});

it('sends bcc recipients through an explicit ses destination', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();
    Http::fake([
        'https://email.*.amazonaws.com/v2/email/outbound-emails' => Http::response(['MessageId' => 'ses-message-2']),
    ]);

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'cc' => ['Ana <ana@example.com>'],
        'bcc' => ['Bea <bea@example.com>'],
        'subject' => 'Monthly invoice',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ])->assertAccepted();

    $email = Email::query()->firstOrFail();

    expect($email->recipients()->where('type', 'bcc')->pluck('email')->all())->toBe(['bea@example.com']);

    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/v2/email/outbound-emails')) {
            return false;
        }

        $destination = $request->data()['Destination'] ?? [];

        return ($destination['ToAddresses'] ?? []) === ['maya@example.com']
            && ($destination['CcAddresses'] ?? []) === ['ana@example.com']
            && ($destination['BccAddresses'] ?? []) === ['bea@example.com'];
    });

    expect($email->fresh()->status)->toBe('sent')
        ->and($email->fresh()->ses_message_id)->toBe('ses-message-2')
        ->and($workspace)->toBeInstanceOf(Workspace::class)
        ->and($project)->toBeInstanceOf(Project::class);
});

it('blocks sends to suppressed recipients', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    Queue::fake();

    Suppression::create([
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'email' => 'maya@example.com',
        'reason' => 'complaint',
        'event_type' => 'complaint',
    ]);

    $this->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Welcome to Larasend',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('to');

    expect(Email::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('lists and shows only emails scoped to the api key project', function () {
    [$workspace, $project, $source, $token] = larasendProjectFixture();

    $email = Email::create([
        'public_id' => 'email_test',
        'workspace_id' => $workspace->id,
        'project_id' => $project->id,
        'source_id' => $source->id,
        'status' => 'delivered',
        'from_email' => 'receipts@example.com',
        'subject' => 'Receipt',
    ]);
    $email->recipients()->create(['type' => 'to', 'email' => 'maya@example.com']);

    $this->withToken($token)
        ->getJson('/api/emails')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', 'email_test');

    $this->withToken($token)
        ->getJson('/api/emails/email_test')
        ->assertSuccessful()
        ->assertJsonPath('data.subject', 'Receipt');
});

it('rejects missing api keys', function () {
    $this->postJson('/api/emails', [])->assertUnauthorized();
});

it('rejects expired api keys', function () {
    [, $project, $source] = larasendProjectFixture();
    $issued = ApiKey::issue($project, 'Expired key', $source, ['send', 'read:activity'], now()->subDay());

    $this->withToken($issued['plain_text'])
        ->getJson('/api/emails')
        ->assertUnauthorized();
});

it('rejects api keys after they are deleted', function () {
    [, $project, $source] = larasendProjectFixture();
    $issued = ApiKey::issue($project, 'Revoked key', $source);
    $issued['api_key']->delete();

    $this->withToken($issued['plain_text'])
        ->getJson('/api/emails')
        ->assertUnauthorized();
});

it('prevents an api key from one project reading emails from another project', function () {
    [$workspaceA, $projectA, $sourceA, $tokenA] = larasendProjectFixture();

    $workspaceB = Workspace::create(['owner_id' => User::factory()->create()->id, 'name' => 'Other', 'slug' => 'other-workspace']);
    $projectB = Project::create(['workspace_id' => $workspaceB->id, 'name' => 'lighthouse', 'slug' => 'lighthouse']);
    $sourceB = Source::create([
        'project_id' => $projectB->id,
        'name' => 'Production',
        'webhook_token' => 'token-'.str()->random(8),
    ]);

    $emailB = Email::create([
        'public_id' => 'email_other_project',
        'workspace_id' => $workspaceB->id,
        'project_id' => $projectB->id,
        'source_id' => $sourceB->id,
        'status' => 'delivered',
        'from_email' => 'receipts@other.example',
        'subject' => 'Not yours',
    ]);

    expect($workspaceA)->toBeInstanceOf(Workspace::class)
        ->and($projectA)->toBeInstanceOf(Project::class)
        ->and($sourceA)->toBeInstanceOf(Source::class);

    $this->withToken($tokenA)
        ->getJson('/api/emails')
        ->assertSuccessful()
        ->assertJsonMissing(['id' => 'email_other_project']);

    $this->withToken($tokenA)
        ->getJson("/api/emails/{$emailB->public_id}")
        ->assertNotFound();
});

it('enforces api key scopes and records usage origin', function () {
    [$workspace, $project, $source] = larasendProjectFixture();
    $issued = ApiKey::issue($project, 'Read only key', $source, ['read:activity']);

    $this->withToken($issued['plain_text'])
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => 'LarasendTest/1.0'])
        ->getJson('/api/emails')
        ->assertSuccessful();

    $this->withToken($issued['plain_text'])
        ->postJson('/api/emails', [
            'from' => 'Larasend <receipts@example.com>',
            'to' => ['Maya <maya@example.com>'],
            'subject' => 'Blocked by scope',
            'html' => '<h1>Hello Maya</h1>',
        ])
        ->assertForbidden();

    $apiKey = $issued['api_key']->fresh();

    expect($workspace)->toBeInstanceOf(Workspace::class)
        ->and($apiKey?->last_used_at)->not->toBeNull()
        ->and($apiKey?->last_used_ip)->toBe('203.0.113.10')
        ->and($apiKey?->last_used_user_agent)->toBe('LarasendTest/1.0')
        ->and(Email::query()->where('subject', 'Blocked by scope')->exists())->toBeFalse();
});
