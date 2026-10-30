<?php

use App\Models\Email;
use App\Models\EmailEvent;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Providers\EmailProviderFactory;
use App\Services\SesEventNormalizer;
use App\Services\SesV2Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('rejects stale snapshot correlation when another connection already bound a different SES message', function (): void {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires an isolated native MySQL test database.');
    }

    $configuration = DB::connection()->getConfig();
    $database = $configuration['database'] ?? '';

    if (($configuration['host'] ?? null) !== '127.0.0.1'
        || (string) ($configuration['port'] ?? '') !== '3308'
        || preg_match('/\Alarasend_issue7_[0-9]{8}_[a-f0-9]{8}\z/', $database) !== 1) {
        throw new LogicException('MySQL snapshot tests require a dedicated local task database.');
    }

    $markerPath = '/private/tmp/'.$database.'_mysql.json';

    if (! is_file($markerPath)) {
        throw new LogicException('The local test database ownership marker is missing.');
    }

    $marker = json_decode(file_get_contents($markerPath), true, flags: JSON_THROW_ON_ERROR);

    if (($marker['created_by_task'] ?? false) !== true || ($marker['database'] ?? null) !== $database
        || ($marker['driver'] ?? null) !== 'mysql' || ($marker['host'] ?? null) !== '127.0.0.1'
        || ($marker['port'] ?? null) !== 3308) {
        throw new LogicException('The local test database ownership marker does not match.');
    }

    expect(DB::transactionLevel())->toBe(0);
    Http::preventStrayRequests();
    Queue::fake();
    $this->mock(EmailProviderFactory::class)->shouldNotReceive('forSource');
    $previousIsolation = DB::scalar('SELECT @@transaction_isolation');
    $bindingConnection = new PDO('mysql:host=127.0.0.1;port=3308;dbname='.$database.';charset=utf8mb4',
        $configuration['username'], $configuration['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Correlation snapshot', 'slug' => fake()->uuid()]);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Correlation snapshot', 'slug' => fake()->uuid()]);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Correlation snapshot', 'webhook_token' => fake()->uuid()]);
    $email = Email::create(['public_id' => 'email_'.Str::random(24), 'workspace_id' => $workspace->id,
        'project_id' => $project->id, 'source_id' => $source->id, 'from_email' => 'sender@example.com',
        'subject' => 'Correlation snapshot', 'status' => 'sending']);
    $correlation = Str::random(48);
    $email->events()->create(['source_id' => $source->id, 'event_type' => 'submission_started',
        'submission_correlation_id' => $correlation, 'payload' => [], 'occurred_at' => now()]);

    try {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        expect(Email::query()->whereKey($email->id)->value('ses_message_id'))->toBeNull();
        $bindingConnection->beginTransaction();
        $statement = $bindingConnection->prepare('UPDATE emails SET ses_message_id = ? WHERE id = ?');
        $statement->execute(['ses-bound-first', $email->id]);
        $bindingConnection->commit();

        $event = app(SesEventNormalizer::class)->record($source, [
            'eventType' => 'Delivery',
            'mail' => ['messageId' => 'ses-conflicting-second', 'timestamp' => now()->toISOString(),
                'tags' => [SesV2Client::CORRELATION_TAG => [$correlation]]],
            'delivery' => ['timestamp' => now()->toISOString(), 'recipients' => ['recipient@example.net']],
        ], 'sns-snapshot-'.Str::random(16));

        expect(Email::query()->whereKey($email->id)->lockForUpdate()->value('ses_message_id'))->toBe('ses-bound-first')
            ->and($event->fresh()->email_id)->toBeNull()
            ->and($event->fresh()->public_event_data)->toBeNull()
            ->and(Email::query()->whereKey($email->id)->lockForUpdate()->value('event_sequence'))->toBe(0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    } finally {
        if ($bindingConnection->inTransaction()) {
            $bindingConnection->rollBack();
        }

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        EmailEvent::query()->where('source_id', $source->id)->delete();
        $email->delete();
        $source->delete();
        $project->delete();
        $workspace->delete();
        $user->delete();

        if (! in_array($previousIsolation, ['READ-UNCOMMITTED', 'READ-COMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE'], true)) {
            throw new LogicException('Unexpected MySQL transaction isolation level.');
        }

        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.str_replace('-', ' ', $previousIsolation));
    }
});
