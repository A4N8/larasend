<?php

use App\Models\ApiKey;
use App\Models\Email;
use App\Models\InboundEmail;
use App\Models\User;
use App\Support\ProjectContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InboxDemoSeeder;
use Illuminate\Support\Facades\Storage;

it('refuses both demo seeders in production', function (string $seeder) {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app($seeder)->run())->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
})->with([DatabaseSeeder::class, InboxDemoSeeder::class]);

it('isolates demo records and preserves customer records tokens scopes and source ids', function () {
    Storage::fake();
    $owner = User::factory()->create();
    $project = app(ProjectContext::class)->projectFor($owner);
    $source = $project->sources()->firstOrFail();
    $key = ApiKey::issue($project, 'Customer')['api_key'];
    $originalKey = $key->fresh()->getAttributes();
    $originalSource = $source->getAttributes();
    $email = $project->emails()->create([
        'public_id' => 'email_customer', 'workspace_id' => $project->workspace_id,
        'source_id' => $source->id, 'status' => 'delivered', 'from_email' => 'customer@example.com', 'subject' => 'Customer data',
    ]);

    $this->seed(DatabaseSeeder::class);
    $this->seed(InboxDemoSeeder::class);

    expect($email->fresh())->not->toBeNull()
        ->and($project->fresh()->name)->toBe('My Project')
        ->and($key->fresh()->getAttributes())->toBe($originalKey)
        ->and($source->fresh()->getAttributes())->toBe($originalSource)
        ->and($project->inboundEmails()->count())->toBe(0);
});

it('does not delete or rotate existing demo data when rerun', function () {
    Storage::fake();
    $this->seed(DatabaseSeeder::class);
    $this->seed(InboxDemoSeeder::class);
    $emails = Email::query()->pluck('id')->all();
    $inbound = InboundEmail::query()->pluck('id')->all();
    $keys = ApiKey::query()->pluck('key_hash', 'id')->all();
    $this->seed(DatabaseSeeder::class);
    $this->seed(InboxDemoSeeder::class);

    expect(Email::query()->pluck('id')->all())->toBe($emails)
        ->and(InboundEmail::query()->pluck('id')->all())->toBe($inbound)
        ->and(ApiKey::query()->pluck('key_hash', 'id')->all())->toBe($keys);
});

it('never changes an existing user matching the demo address', function () {
    Storage::fake();
    $user = User::factory()->unverified()->create(['email' => 'demo@larasend.test']);
    $attributes = $user->fresh()->getAttributes();

    $this->seed(DatabaseSeeder::class);

    expect($user->fresh()->getAttributes())->toBe($attributes);
});
