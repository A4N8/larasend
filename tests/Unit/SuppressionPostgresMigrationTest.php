<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

it('preserves canonical historical data and recovers failed concurrent indexes on isolated PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated local PostgreSQL audit database.');
    }

    $configuration = DB::connection()->getConfig();

    if (($configuration['host'] ?? null) !== '127.0.0.1'
        || preg_match('/\Alarasend_phase2_audit_20261029_[a-f0-9]{8}\z/', $configuration['database'] ?? '') !== 1) {
        throw new LogicException('PostgreSQL migration tests require the dedicated local task database.');
    }

    Http::preventStrayRequests();
    DB::statement("SET statement_timeout = '10000ms'");
    $slug = 'pg-canonical-'.Str::lower(Str::random(8));
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => $slug, 'slug' => $slug]);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => $slug, 'slug' => $slug]);
    $other = Project::create(['workspace_id' => $workspace->id, 'name' => 'Other', 'slug' => $slug.'-other']);
    $migration = require database_path('migrations/2026_10_29_124216_add_canonical_suppression_email_unique_index.php');
    $insert = fn (Project $target, string $address): int => DB::table('suppressions')->insertGetId([
        'workspace_id' => $target->workspace_id, 'project_id' => $target->id, 'email' => $address,
        'reason' => 'complaint', 'event_type' => 'complaint', 'expires_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $validIndex = fn (): bool => (bool) DB::selectOne("SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = 'suppressions_project_normalized_email_unique' AND n.nspname = current_schema()")?->indisvalid;

    expect(DB::transactionLevel())->toBe(0)->and($validIndex())->toBeTrue();
    DB::statement('DROP INDEX CONCURRENTLY suppressions_project_normalized_email_unique');
    $id = $insert($project, ' Historical@EXAMPLE.COM ');
    $before = DB::table('suppressions')->where('id', $id)->first();
    $migration->up();
    $migration->up();

    expect($validIndex())->toBeTrue()
        ->and(DB::table('suppressions')->where('id', $id)->first())->toEqual($before)
        ->and($project->suppressions()->whereNormalizedEmail('historical@example.com')->sole()->id)->toBe($id);
    expect(fn () => $insert($project, 'historical@example.com'))->toThrow(UniqueConstraintViolationException::class);
    $winner = $project->suppressions()->whereNormalizedEmail('historical@example.com')
        ->createOrFirst([], ['workspace_id' => $workspace->id, 'email' => 'historical@example.com', 'reason' => 'hard_bounce', 'event_type' => 'bounce']);
    expect($winner->id)->toBe($id)->and($winner->wasRecentlyCreated)->toBeFalse();
    $insert($other, 'historical@example.com');
    $insert($project, 'Ä@example.com');
    $insert($project, 'ä@example.com');
    expect($project->suppressions()->count())->toBe(3)
        ->and($project->suppressions()->whereNormalizedEmailIn(['Ä@EXAMPLE.COM'])->count())->toBe(1);

    DB::statement('DROP INDEX CONCURRENTLY suppressions_project_normalized_email_unique');
    $loserId = $insert($project, 'historical@example.com');
    $snapshot = DB::table('suppressions')->orderBy('id')->get();
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'canonical suppression collision');
    expect(DB::table('suppressions')->orderBy('id')->get())->toEqual($snapshot);

    $expression = 'TRIM(email)';

    foreach (range('A', 'Z') as $uppercase) {
        $expression = sprintf("REPLACE(%s, '%s', '%s')", $expression, $uppercase, strtolower($uppercase));
    }

    expect(fn () => DB::statement('CREATE UNIQUE INDEX CONCURRENTLY suppressions_project_normalized_email_unique ON suppressions (project_id, '.$expression.')'))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($validIndex())->toBeFalse();
    DB::table('suppressions')->where('id', $loserId)->delete();
    $beforeRetry = DB::table('suppressions')->orderBy('id')->get();
    $migration->up();
    expect($validIndex())->toBeTrue()
        ->and(DB::table('suppressions')->orderBy('id')->get())->toEqual($beforeRetry);
    Http::assertNothingSent();
});
