<?php

use App\Models\EmailEvent;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('restores PostgreSQL lock timeouts and repairs invalid event publication indexes without changing evidence', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated local PostgreSQL test database.');
    }

    $configuration = DB::connection()->getConfig();

    if (($configuration['host'] ?? null) !== '127.0.0.1'
        || (string) ($configuration['port'] ?? '') !== '5432'
        || preg_match('/\Alarasend_issue7_[0-9]{8}_[a-f0-9]{8}\z/', $configuration['database'] ?? '') !== 1) {
        throw new LogicException('Event publication migration tests require a dedicated local task database.');
    }

    Http::preventStrayRequests();
    $previousLockTimeout = DB::scalar('SHOW lock_timeout');
    $previousStatementTimeout = DB::scalar('SHOW statement_timeout');
    DB::statement("SET statement_timeout = '10000ms'");
    DB::statement("SET lock_timeout = '1379ms'");
    $migration = require database_path('migrations/2026_10_30_102934_add_event_publication_indexes.php');
    $validIndexes = fn (): array => DB::select("SELECT c.relname, i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname IN ('email_events_email_id_email_sequence_unique', 'email_events_submission_correlation_id_unique', 'email_events_email_id_id_index', 'email_events_source_id_ses_message_id_index') AND n.nspname = current_schema() ORDER BY c.relname");
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Publication migration', 'slug' => fake()->uuid()]);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'Publication migration', 'slug' => fake()->uuid()]);
    $source = Source::create(['project_id' => $project->id, 'name' => 'Publication migration', 'webhook_token' => fake()->uuid()]);

    try {
        expect(DB::transactionLevel())->toBe(0);
        $migration->up();
        $migration->up();

        expect(DB::scalar('SHOW lock_timeout'))->toBe('1379ms')
            ->and($validIndexes())->toHaveCount(4);

        foreach ($validIndexes() as $index) {
            expect($index->indisvalid)->toBeTrue();
        }

        DB::statement('ALTER TABLE email_events DROP CONSTRAINT email_events_submission_correlation_id_unique');
        $attributes = ['source_id' => $source->id, 'event_type' => 'submission_started',
            'submission_correlation_id' => 'migration-'.fake()->uuid(), 'payload' => [], 'occurred_at' => now()];
        $first = EmailEvent::create($attributes);
        $duplicate = EmailEvent::create($attributes);
        $before = EmailEvent::query()->where('source_id', $source->id)->orderBy('id')->get()->map->getRawOriginal()->all();

        expect(fn () => $migration->up())->toThrow(UniqueConstraintViolationException::class);
        expect(DB::scalar('SHOW lock_timeout'))->toBe('1379ms')
            ->and(EmailEvent::query()->where('source_id', $source->id)->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before)
            ->and(collect($validIndexes())->firstWhere('relname', 'email_events_submission_correlation_id_unique')->indisvalid)->toBeFalse();

        $duplicate->delete();
        $retainedEvidence = $first->fresh()->getRawOriginal();
        $migration->up();

        expect(DB::scalar('SHOW lock_timeout'))->toBe('1379ms')
            ->and($first->fresh()->getRawOriginal())->toBe($retainedEvidence)
            ->and($validIndexes())->toHaveCount(4);

        foreach ($validIndexes() as $index) {
            expect($index->indisvalid)->toBeTrue();
        }

        Http::assertNothingSent();
    } finally {
        EmailEvent::query()->where('source_id', $source->id)->delete();
        $source->delete();
        $project->delete();
        $workspace->delete();
        $user->delete();
        DB::selectOne("SELECT set_config('lock_timeout', ?, false)", [$previousLockTimeout]);
        DB::selectOne("SELECT set_config('statement_timeout', ?, false)", [$previousStatementTimeout]);
    }
});
