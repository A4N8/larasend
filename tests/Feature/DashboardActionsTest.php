<?php

use App\Jobs\SendQueuedEmail;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\DnsRecordVerifier;
use App\Services\EmailSendService;
use App\Services\WebhookUrlGuard;
use App\Support\ProjectContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ZBateson\MailMimeParser\MailMimeParser;

beforeEach(function (): void {
    app()->instance(WebhookUrlGuard::class, new class extends WebhookUrlGuard
    {
        protected function resolveHost(string $host): array
        {
            return ['1.1.1.1'];
        }
    });

    // Domain rechecks dispatched during these tests run inline on the sync
    // queue; stub the DoH resolvers so no real DNS traffic leaves the suite.
    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response(['Status' => 3, 'Answer' => []]),
        'https://dns.google/*' => Http::response(['Status' => 3, 'Answer' => []]),
    ]);
});

/**
 * @return array{0: User, 1: Project}
 */
function readOnlyMemberFixture(): array
{
    $owner = User::factory()->create();
    $workspace = app(ProjectContext::class)->workspaceFor($owner);
    $project = app(ProjectContext::class)->projectFor($owner);

    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'read_only']);

    return [$member, $project];
}

function fakeSesIdentityCreation(User $user): Project
{
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();

    $source->forceFill([
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'ses_region' => 'us-east-1',
        'default_from_email' => $source->default_from_email ?: 'receipts@example.com',
    ])->save();

    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/identities' => Http::response([
            'DkimAttributes' => [
                'Tokens' => ['abc123', 'def456', 'ghi789'],
            ],
        ]),
        'https://email.us-east-1.amazonaws.com/v2/email/identities/*' => Http::response([
            'DkimAttributes' => [
                'Tokens' => ['abc123', 'def456', 'ghi789'],
            ],
        ]),
    ]);

    return $project;
}

function configureDashboardSesSending(User $user): Project
{
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrCreate(
        ['environment' => $project->default_environment],
        [
            'name' => 'Production',
            'ses_region' => 'us-east-1',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'webhook_token' => (string) str()->uuid(),
        ],
    );

    $source->forceFill([
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'ses_region' => 'us-east-1',
        'default_from_email' => 'receipts@example.com',
        'last_quota' => [
            'Max24HourSend' => 50000,
            'MaxSendRate' => 200,
            'SentLast24Hours' => 25,
        ],
        'last_quota_checked_at' => now(),
    ])->save();

    $project->domains()->updateOrCreate(
        ['domain' => 'example.com'],
        ['status' => 'verified', 'dns_records' => [], 'verified_at' => now()],
    );

    Http::fake([
        'https://email.us-east-1.amazonaws.com/*' => Http::response(['MessageId' => 'ses-message-1']),
    ]);

    return $project;
}

it('configures a ses source and creates domain dns records', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/source', [
            'name' => 'Production',
            'environment' => 'prod',
            'ses_region' => 'us-east-1',
            'ses_configuration_set' => 'larasend-prod',
            'default_from_name' => 'Larasend',
            'default_from_email' => 'receipts@example.com',
            'retention_days' => 731,
        ])
        ->assertRedirect('/source');

    fakeSesIdentityCreation($user);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'mail.example.com'])
        ->assertRedirect('/identities');

    $project = $user->workspaces()->first()->projects()->first();

    expect($project->sources()->first())
        ->default_from_email->toBe('receipts@example.com')
        ->ses_configuration_set->toBe('larasend-prod')
        ->and($project->domains()->where('domain', 'mail.example.com')->first())
        ->not->toBeNull()
        ->dns_records->toHaveCount(3);
});

it('uses existing ses identity details when aws says the domain already exist', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();

    $source->forceFill([
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'ses_region' => 'us-east-1',
        'default_from_email' => 'receipts@example.com',
    ])->save();

    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/identities' => Http::response([
            'message' => 'Email identity savvyagents.ai already exist.',
        ], 400),
        'https://email.us-east-1.amazonaws.com/v2/email/identities/savvyagents.ai' => Http::response([
            'DkimAttributes' => [
                'Tokens' => ['existing123', 'existing456', 'existing789'],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'savvyagents.ai'])
        ->assertRedirect('/identities');

    $domain = $project->domains()->where('domain', 'savvyagents.ai')->firstOrFail();

    expect($domain->dns_records)
        ->toHaveCount(3)
        ->and($domain->dns_records[0]['value'])->toContain('existing123');
});

it('accepts an email address when creating a sending identity', function () {
    $user = User::factory()->create();
    $project = fakeSesIdentityCreation($user);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'Vijay@Mail.Example.COM'])
        ->assertRedirect('/identities');

    expect($project->domains()->where('domain', 'mail.example.com')->exists())->toBeTrue();
});

it('re-checks domain dns records and stores status results', function () {
    $user = User::factory()->create();
    fakeSesIdentityCreation($user);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'mail.example.com'])
        ->assertRedirect('/identities');

    $project = $user->workspaces()->first()->projects()->first();
    $domain = $project->domains()->where('domain', 'mail.example.com')->firstOrFail();

    app()->instance(DnsRecordVerifier::class, new class extends DnsRecordVerifier
    {
        public function matches(array $record): bool
        {
            return true;
        }
    });

    $this->actingAs($user)
        ->post("/domains/{$domain->id}/check-dns")
        ->assertRedirect('/identities');

    $domain->refresh();

    expect($domain->status)->toBe('verified')
        ->and($domain->verified_at)->not->toBeNull()
        ->and($domain->dns_records[0]['status'])->toBe('ok');
});

it('re-checks domain dns records from project-scoped routes', function () {
    $user = User::factory()->create();
    fakeSesIdentityCreation($user);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'scoped.example.com'])
        ->assertRedirect('/identities');

    $project = $user->workspaces()->first()->projects()->first();
    $domain = $project->domains()->where('domain', 'scoped.example.com')->firstOrFail();

    app()->instance(DnsRecordVerifier::class, new class extends DnsRecordVerifier
    {
        public function matches(array $record): bool
        {
            return true;
        }
    });

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/domains/{$domain->id}/check-dns")
        ->assertRedirect("/projects/{$project->slug}/identities")
        ->assertInertiaFlash('toast');

    expect($domain->refresh()->status)->toBe('verified');
});

it('marks domain dns records pending when dns does not match', function () {
    $user = User::factory()->create();
    fakeSesIdentityCreation($user);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'missing.example.com'])
        ->assertRedirect('/identities');

    $project = $user->workspaces()->first()->projects()->first();
    $domain = $project->domains()->where('domain', 'missing.example.com')->firstOrFail();

    app()->instance(DnsRecordVerifier::class, new class extends DnsRecordVerifier
    {
        public function matches(array $record): bool
        {
            return false;
        }
    });

    $this->actingAs($user)
        ->post("/domains/{$domain->id}/check-dns")
        ->assertRedirect('/identities');

    $domain->refresh();

    expect($domain->status)->toBe('pending')
        ->and($domain->verified_at)->toBeNull()
        ->and($domain->dns_records[0]['status'])->toBe('pending');
});

it('prevents users from re-checking domains outside their workspace', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    fakeSesIdentityCreation($owner);

    $this->actingAs($owner)
        ->post('/domains', ['domain' => 'private.example.com'])
        ->assertRedirect('/identities');

    $domain = $owner->workspaces()->first()->projects()->first()->domains()->firstOrFail();

    $this->actingAs($other)
        ->post("/domains/{$domain->id}/check-dns")
        ->assertNotFound();
});

it('deletes a domain and clears source linkage', function () {
    $user = User::factory()->create();
    fakeSesIdentityCreation($user);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'delete.example.com'])
        ->assertRedirect('/identities');

    $project = $user->workspaces()->first()->projects()->first();
    $domain = $project->domains()->where('domain', 'delete.example.com')->firstOrFail();

    expect($project->sources()->where('domain_id', $domain->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->delete("/domains/{$domain->id}")
        ->assertRedirect('/identities');

    expect($project->domains()->where('domain', 'delete.example.com')->exists())->toBeFalse()
        ->and($project->sources()->where('domain_id', $domain->id)->exists())->toBeFalse();
});

it('deletes a domain through the project-scoped route', function () {
    $user = User::factory()->create();
    fakeSesIdentityCreation($user);

    $this->actingAs($user)
        ->post('/domains', ['domain' => 'scoped-delete.example.com'])
        ->assertRedirect('/identities');

    $project = $user->workspaces()->first()->projects()->first();
    $domain = $project->domains()->where('domain', 'scoped-delete.example.com')->firstOrFail();

    $this->actingAs($user)
        ->delete("/projects/{$project->slug}/domains/{$domain->id}")
        ->assertRedirect("/projects/{$project->slug}/identities");

    expect($project->domains()->where('domain', 'scoped-delete.example.com')->exists())->toBeFalse();
});

it('prevents users from deleting domains outside their workspace', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    fakeSesIdentityCreation($owner);

    $this->actingAs($owner)
        ->post('/domains', ['domain' => 'delete-private.example.com'])
        ->assertRedirect('/identities');

    $domain = $owner->workspaces()->first()->projects()->first()->domains()->firstOrFail();

    $this->actingAs($other)
        ->delete("/domains/{$domain->id}")
        ->assertNotFound();
});

it('creates templates and one-time api keys from the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/templates', [
            'slug' => 'tx.receipt.v1',
            'name' => 'Receipt',
            'subject' => 'Receipt for {{name}}',
            'html' => '<p>Hello {{name}}</p>',
            'text' => 'Hello {{name}}',
            'variables' => 'name, invoice',
        ])
        ->assertRedirect('/templates');

    $this->actingAs($user)
        ->post('/api-keys', ['name' => 'Production key'])
        ->assertRedirect('/api-keys')
        ->assertInertiaFlash('toast')
        ->assertSessionHas('newApiKey', fn (string $key) => str_starts_with($key, 'ls_'));

    $project = $user->workspaces()->first()->projects()->first();

    expect($project->templates()->where('slug', 'tx.receipt.v1')->first())
        ->not->toBeNull()
        ->variables->toBe(['name', 'invoice'])
        ->and(ApiKey::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('forbids read-only workspace members from creating templates', function () {
    [$member, $project] = readOnlyMemberFixture();

    $this->actingAs($member)
        ->post('/templates', [
            'slug' => 'tx.receipt.v1',
            'name' => 'Receipt',
            'subject' => 'Receipt for {{name}}',
        ])
        ->assertForbidden();

    expect($project->templates()->where('slug', 'tx.receipt.v1')->exists())->toBeFalse();
});

it('deletes api keys from the dashboard', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $apiKey = ApiKey::issue($project, 'Production key')['api_key'];

    $this->actingAs($user)
        ->delete("/api-keys/{$apiKey->id}")
        ->assertRedirect('/api-keys');

    expect($apiKey->fresh())->toBeNull();
});

it('deletes project scoped api keys from the dashboard', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $apiKey = ApiKey::issue($project, 'Production key')['api_key'];

    $this->actingAs($user)
        ->delete("/projects/{$project->slug}/api-keys/{$apiKey->id}")
        ->assertRedirect("/projects/{$project->slug}/api-keys");

    expect($apiKey->fresh())->toBeNull();
});

it('syncs ses source quota from aws', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();
    $source->forceFill([
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'ses_region' => 'us-east-1',
    ])->save();

    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/account' => Http::response([
            'SendQuota' => [
                'Max24HourSend' => 50000,
                'MaxSendRate' => 200,
                'SentLast24Hours' => 125,
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/source/quota")
        ->assertRedirect("/projects/{$project->slug}/source");

    $source->refresh();

    expect($source->last_quota_checked_at)->not->toBeNull()
        ->and($source->last_quota['max_24_hour_send'])->toBe(50000)
        ->and($source->last_quota['max_send_rate'])->toBe(200);
});

it('can sync ses quota silently for setup automation', function () {
    $user = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($user);
    $source = $project->sources()->firstOrFail();
    $source->forceFill([
        'aws_access_key_id' => 'test-access-key',
        'aws_secret_access_key' => 'test-secret-key',
        'ses_region' => 'us-east-1',
    ])->save();

    Http::fake([
        'https://email.us-east-1.amazonaws.com/v2/email/account' => Http::response([
            'SendQuota' => [
                'Max24HourSend' => 1000,
                'MaxSendRate' => 25,
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/source/quota", ['silent' => true])
        ->assertRedirect("/projects/{$project->slug}/source")
        ->assertSessionMissing('toast');

    expect($source->fresh()->last_quota['max_24_hour_send'])->toBe(1000);
});

it('prevents users from deleting api keys outside their workspace', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($owner);
    $apiKey = ApiKey::issue($project, 'Private key')['api_key'];

    $this->actingAs($other)
        ->delete("/api-keys/{$apiKey->id}")
        ->assertNotFound();

    expect($apiKey->fresh())->not->toBeNull();
});

it('creates and updates webhook endpoints with one-time signing secret reveal', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/webhooks', [
            'url' => 'https://example.com/webhooks/larasend',
            'events' => ['delivery', 'bounce', 'complaint'],
            'status' => 'active',
        ])
        ->assertRedirect('/webhooks')
        ->assertInertiaFlash('toast')
        ->assertSessionHas('newWebhookEndpoint.secret', fn (string $secret) => str_starts_with($secret, 'whsec_'));

    $project = $user->workspaces()->first()->projects()->first();
    $endpoint = $project->webhookEndpoints()->where('url', 'https://example.com/webhooks/larasend')->firstOrFail();

    $this->actingAs($user)
        ->put("/webhooks/{$endpoint->public_id}", [
            'url' => 'https://example.com/webhooks/updated',
            'events' => ['delivery'],
            'status' => 'paused',
        ])
        ->assertRedirect('/webhooks');

    $endpoint->refresh();

    expect($endpoint->url)->toBe('https://example.com/webhooks/updated')
        ->and($endpoint->events)->toBe(['delivery'])
        ->and($endpoint->status)->toBe('paused');
});

it('forbids read-only workspace members from creating or updating webhook endpoints', function () {
    [$member, $project] = readOnlyMemberFixture();

    $this->actingAs($member)
        ->post('/webhooks', [
            'url' => 'https://attacker.example/collect',
            'events' => ['delivery', 'bounce', 'complaint'],
            'status' => 'active',
        ])
        ->assertForbidden();

    expect($project->webhookEndpoints()->where('url', 'https://attacker.example/collect')->exists())->toBeFalse();

    $endpoint = WebhookEndpoint::issue($project, 'https://example.com/webhooks/existing', ['delivery'])['endpoint'];

    $this->actingAs($member)
        ->put("/webhooks/{$endpoint->public_id}", [
            'url' => 'https://attacker.example/collect',
            'events' => ['delivery'],
            'status' => 'active',
        ])
        ->assertForbidden();

    expect($endpoint->fresh()->url)->toBe('https://example.com/webhooks/existing');
});

it('prevents users from updating webhook endpoints outside their workspace', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($owner)
        ->post('/webhooks', [
            'url' => 'https://example.com/webhooks/private',
            'events' => ['delivery'],
            'status' => 'active',
        ])
        ->assertRedirect('/webhooks');

    $endpoint = WebhookEndpoint::query()->where('url', 'https://example.com/webhooks/private')->firstOrFail();

    $this->actingAs($other)
        ->put("/webhooks/{$endpoint->public_id}", [
            'url' => 'https://example.com/webhooks/stolen',
            'events' => ['delivery'],
            'status' => 'active',
        ])
        ->assertNotFound();
});

it('blocks dashboard sends until SES credentials and a verified domain exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/send', [
            'from' => 'Larasend <receipts@example.com>',
            'to' => 'maya@example.com, team@example.com',
            'cc' => '',
            'bcc' => '',
            'subject' => 'Local SES test',
            'html' => '<p>Delivered locally</p>',
            'text' => 'Delivered locally',
        ])
        ->assertRedirect('/identities')
        ->assertSessionHasErrors('send')
        ->assertInertiaFlash('toast');

    expect(Email::query()->where('subject', 'Local SES test')->exists())->toBeFalse();
});

it('sends dashboard emails through the configured SES source', function () {
    $user = User::factory()->create();

    configureDashboardSesSending($user);
    Queue::fake();

    $this->actingAs($user)
        ->post('/send', [
            'from' => 'Larasend <receipts@example.com>',
            'to' => 'maya@example.com, team@example.com',
            'cc' => '',
            'bcc' => '',
            'subject' => 'SES test',
            'html' => '<p>Delivered through SES</p>',
            'text' => 'Delivered through SES',
        ])
        ->assertRedirect('/activity');

    $email = Email::query()->where('subject', 'SES test')->first();

    expect($email)
        ->not->toBeNull()
        ->status->toBe('queued')
        ->ses_message_id->toBeNull()
        ->and($email->recipients()->where('type', 'to')->count())->toBe(2)
        ->and($email->events()->where('event_type', 'send')->exists())->toBeFalse();

    Queue::assertPushed(SendQueuedEmail::class, fn (SendQueuedEmail $job) => $job->emailId === $email->id);
    Http::assertNothingSent();
});

it('resends stored emails from the inspector action', function () {
    $user = User::factory()->create();

    configureDashboardSesSending($user);
    Queue::fake();

    $this->actingAs($user)
        ->post('/send', [
            'from' => 'Larasend <receipts@example.com>',
            'to' => 'maya@example.com',
            'subject' => 'Resend me',
            'html' => '<p>Original</p>',
            'text' => 'Original',
        ])
        ->assertRedirect('/activity');

    $original = Email::query()->where('subject', 'Resend me')->firstOrFail();

    $this->actingAs($user)
        ->post("/emails/{$original->public_id}/resend")
        ->assertRedirect('/activity');

    $resent = Email::query()->where('subject', 'Resend me')->latest('id')->first();

    expect(Email::query()->where('subject', 'Resend me')->count())->toBe(2)
        ->and($resent?->tags['resent_from'] ?? null)->toBe($original->public_id)
        ->and($resent?->status)->toBe('queued');

    Queue::assertPushed(SendQueuedEmail::class, 2);
});

it('rejects dashboard attachments instead of forwarding partial validated inline parts', function (): void {
    $user = User::factory()->create();
    configureDashboardSesSending($user);
    Queue::fake();

    $this->actingAs($user)->postJson('/send', [
        'from' => 'receipts@example.com', 'to' => 'maya@example.com',
        'subject' => 'Unsupported dashboard attachment', 'html' => '<p>Hello</p>',
        'attachments' => [['filename' => 'logo.png', 'content' => base64_encode('bytes'), 'content_type' => 'image/png', 'content_id' => 'logo@example.com']],
    ])->assertUnprocessable()->assertJsonValidationErrors('attachments');

    expect(Email::query()->where('subject', 'Unsupported dashboard attachment')->exists())->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('preserves archived inline parts and ordinary documents on dashboard resend and soft-bounce retry', function (string $action): void {
    $user = User::factory()->create();
    $project = configureDashboardSesSending($user);
    Storage::fake('local');
    Queue::fake();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $original = app(EmailSendService::class)->send($project, $project->sources()->firstOrFail(), [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'],
        'subject' => 'Archived inline resend', 'html' => '<p>Hello</p><img src="cid:Logo@example.com">', 'text' => 'Hello',
        'attachments' => [
            ['filename' => 'logo.png', 'content' => base64_encode($png), 'content_type' => 'image/png', 'content_id' => 'Logo@example.com'],
            ['filename' => 'statement.pdf', 'content' => base64_encode('original-pdf-bytes'), 'content_type' => 'application/pdf'],
        ],
    ]);
    if ($action === 'soft bounce') {
        $original->forceFill(['status' => 'bounced', 'tags' => ['bounce_type' => 'soft']])->save();
    }
    Storage::fake('new-default');
    config(['filesystems.default' => 'new-default']);
    $url = match ($action) {
        'soft bounce' => '/bounces/retry-soft',
        'project resend' => "/projects/{$project->slug}/emails/{$original->public_id}/resend",
        default => "/emails/{$original->public_id}/resend",
    };

    $this->actingAs($user)->post($url)->assertRedirect();

    $resent = Email::query()->where('subject', $original->subject)->whereKeyNot($original->id)->sole();
    $mime = app(MailMimeParser::class)->parse(Storage::disk($resent->mime_disk)->get($resent->mime_path), false);
    $parts = collect($mime->getAllAttachmentParts());
    $inline = $parts->first(fn ($part) => $part->getContentDisposition() === 'inline');
    $document = $parts->first(fn ($part) => $part->getFilename() === 'statement.pdf');
    expect($resent->html)->toBe($original->html)
        ->and($inline?->getContentId())->toBe('Logo@example.com')
        ->and($inline?->getContent())->toBe($png)
        ->and($inline?->getContentType())->toBe('image/png')
        ->and($document?->getContent())->toBe('original-pdf-bytes')
        ->and($document?->getContentDisposition())->toBe('attachment')
        ->and($resent->attachments()->count())->toBe(2);
    Queue::assertPushed(SendQueuedEmail::class, 2);
    Http::assertNothingSent();
})->with(['resend', 'project resend', 'soft bounce']);

it('does not queue a broken resend when required archived parts cannot be recovered', function (string $state, string $action): void {
    $user = User::factory()->create();
    $project = configureDashboardSesSending($user);
    Storage::fake('local');
    Queue::fake();
    $original = app(EmailSendService::class)->send($project, $project->sources()->firstOrFail(), [
        'from' => 'receipts@example.com', 'to' => ['maya@example.com'],
        'subject' => 'Unavailable inline archive', 'html' => '<img src="cid:logo@example.com">',
        'attachments' => [['filename' => 'logo.png', 'content' => base64_encode('original-bytes'), 'content_type' => 'image/png', 'content_id' => 'logo@example.com']],
    ]);
    if ($state === 'missing') {
        Storage::disk('local')->delete($original->mime_path);
    } elseif ($state === 'malformed') {
        Storage::disk('local')->put($original->mime_path, "Content-Type: text/plain\r\n\r\ntruncated archive");
    } else {
        Storage::disk('local')->put($original->mime_path, str_replace('logo@example.com', 'other@example.com', Storage::disk('local')->get($original->mime_path)));
    }
    if ($action === 'soft bounce') {
        $original->forceFill(['status' => 'bounced', 'tags' => ['bounce_type' => 'soft']])->save();
    }
    $url = $action === 'soft bounce' ? '/bounces/retry-soft' : "/emails/{$original->public_id}/resend";
    $response = $this->actingAs($user)->post($url)
        ->assertRedirect()->assertInertiaFlash('toast.type', 'error');
    if ($action === 'soft bounce') {
        $response->assertInertiaFlash('toast.message', 'Retried 0 soft bounces. Could not retry 1 email; no replacement was queued for it.');
    }

    expect(Email::query()->where('subject', $original->subject)->count())->toBe(1);
    Queue::assertPushed(SendQueuedEmail::class, 1);
    Http::assertNothingSent();
})->with(['missing', 'malformed', 'wrong content ID'])->with(['resend', 'soft bounce']);

it('resends stored emails from project scoped activity routes', function () {
    $user = User::factory()->create();

    $project = configureDashboardSesSending($user);
    Queue::fake();

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/send", [
            'from' => 'receipts@example.com',
            'to' => 'maya@example.com',
            'subject' => 'Project scoped resend',
            'html' => '<p>Original</p>',
            'text' => 'Original',
        ])
        ->assertRedirect("/projects/{$project->slug}/activity");

    $original = Email::query()->where('subject', 'Project scoped resend')->firstOrFail();

    $this->actingAs($user)
        ->post("/projects/{$project->slug}/emails/{$original->public_id}/resend")
        ->assertRedirect("/projects/{$project->slug}/activity");

    $resent = Email::query()->where('subject', 'Project scoped resend')->latest('id')->first();

    expect(Email::query()->where('subject', 'Project scoped resend')->count())->toBe(2)
        ->and($resent?->tags['resent_from'] ?? null)->toBe($original->public_id)
        ->and($resent?->status)->toBe('queued');

    Queue::assertPushed(SendQueuedEmail::class, 2);
});

it('retries soft bounces from the bounce queue action', function () {
    $user = User::factory()->create();

    configureDashboardSesSending($user);
    Queue::fake();

    $this->actingAs($user)
        ->post('/send', [
            'from' => 'Larasend <receipts@example.com>',
            'to' => 'full@example.com',
            'subject' => 'Retry this soft bounce',
            'html' => '<p>Retry</p>',
            'text' => 'Retry',
        ])
        ->assertRedirect('/activity');

    $email = Email::query()->where('subject', 'Retry this soft bounce')->firstOrFail();
    $email->forceFill(['status' => 'bounced'])->save();
    $email->events()->create([
        'source_id' => $email->source_id,
        'event_type' => 'bounce',
        'ses_message_id' => $email->ses_message_id,
        'recipient' => 'full@example.com',
        'payload' => [
            'bounce' => [
                'bounceType' => 'Transient',
                'bouncedRecipients' => [[
                    'emailAddress' => 'full@example.com',
                    'status' => '452 4.2.2',
                    'diagnosticCode' => 'Mailbox full',
                ]],
            ],
        ],
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->post('/bounces/retry-soft')
        ->assertRedirect('/bounces')
        ->assertInertiaFlash('toast');

    expect(Email::query()->where('subject', 'Retry this soft bounce')->count())->toBe(2);
    Queue::assertPushed(SendQueuedEmail::class, 2);
});
