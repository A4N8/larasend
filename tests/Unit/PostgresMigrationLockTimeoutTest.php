<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

uses(TestCase::class);

it('bounds migration lock waits and restores the previous PostgreSQL session settings', function (string $filename, string $table, array $prepare): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires an isolated local PostgreSQL audit database.');
    }

    $configuration = DB::connection()->getConfig();
    $identity = DB::selectOne('SELECT current_database() AS name, host(inet_server_addr()) AS host');

    if (($configuration['host'] ?? null) !== '127.0.0.1'
        || preg_match('/\Alarasend_phase2_audit_20261029_[a-f0-9]{8}\z/', $configuration['database'] ?? '') !== 1
        || $identity->name !== $configuration['database'] || $identity->host !== '127.0.0.1'
        || ! in_array($table, ['emails', 'email_events', 'suppressions'], true)) {
        throw new LogicException('Migration lock tests require the dedicated local task database.');
    }

    expect(DB::transactionLevel())->toBe(0);
    $previousLockTimeout = DB::scalar('SHOW lock_timeout');
    $previousStatementTimeout = DB::scalar('SHOW statement_timeout');
    $migration = require database_path('migrations/'.$filename);

    foreach ($prepare as $statement) {
        DB::statement($statement);
    }

    $blocker = new PDO('pgsql:host=127.0.0.1;port='.$configuration['port'].';dbname='.$configuration['database'],
        $configuration['username'], $configuration['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $blocker->beginTransaction();
    $blocker->exec('LOCK TABLE '.$table.' IN ACCESS EXCLUSIVE MODE');
    DB::statement("SET lock_timeout = '9s'");
    DB::statement("SET statement_timeout = '7s'");
    $started = microtime(true);
    $failure = null;

    try {
        try {
            $migration->up();
        } catch (QueryException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(QueryException::class)
            ->and($failure?->errorInfo[0])->toBe('55P03')
            ->and(microtime(true) - $started)->toBeLessThan(6.5)
            ->and(DB::scalar('SHOW lock_timeout'))->toBe('9s')
            ->and(DB::transactionLevel())->toBe(0);
    } finally {
        $blocker->rollBack();
        $migration->up();
        expect(DB::scalar('SHOW lock_timeout'))->toBe('9s');
        DB::selectOne("SELECT set_config('lock_timeout', ?, false)", [$previousLockTimeout]);
        DB::selectOne("SELECT set_config('statement_timeout', ?, false)", [$previousStatementTimeout]);
    }
})->with([
    'idempotency columns' => ['2026_10_29_122430_add_idempotency_columns_to_emails_table.php', 'emails', [
        'ALTER TABLE emails DROP CONSTRAINT IF EXISTS emails_project_id_idempotency_key_unique',
        'ALTER TABLE emails DROP COLUMN idempotency_key, DROP COLUMN idempotency_hash',
    ]],
    'event deduplication column' => ['2026_10_29_122431_add_provider_event_id_to_email_events_table.php', 'email_events', [
        'ALTER TABLE email_events DROP CONSTRAINT IF EXISTS email_events_provider_event_id_unique',
        'ALTER TABLE email_events DROP COLUMN provider_event_id',
    ]],
    'provider metadata column' => ['2026_10_29_125803_add_provider_metadata_to_suppressions_table.php', 'suppressions', [
        'ALTER TABLE suppressions DROP COLUMN provider_metadata',
    ]],
    'concurrent reliability indexes' => ['2026_10_29_122432_add_delivery_reliability_indexes.php', 'emails', [
        'ALTER TABLE emails DROP CONSTRAINT IF EXISTS emails_project_id_idempotency_key_unique',
        'ALTER TABLE email_events DROP CONSTRAINT IF EXISTS email_events_provider_event_id_unique',
        'DROP INDEX IF EXISTS emails_status_created_at_id_index',
    ]],
    'concurrent canonical suppression index' => ['2026_10_29_124216_add_canonical_suppression_email_unique_index.php', 'suppressions', [
        'DROP INDEX suppressions_project_normalized_email_unique',
    ]],
]);

it('keeps the original concurrent migration exception when restoring the timeout also fails', function (string $filename): void {
    $original = new RuntimeException('Original index failure');
    $restoreFailure = new RuntimeException('Failed to restore session setting');
    Exceptions::fake();
    DB::shouldReceive('getDriverName')->andReturn('pgsql');
    DB::shouldReceive('scalar')->with('SHOW lock_timeout')->andReturn('9s');
    DB::shouldReceive('statement')->with("SET lock_timeout = '5s'")->once()->andReturn(true);
    DB::shouldReceive('selectOne')->withArgs(fn (string $query): bool => str_contains(strtolower($query), 'pg_index'))->andThrow($original);
    DB::shouldReceive('selectOne')->with("SELECT set_config('lock_timeout', ?, false)", ['9s'])->once()->andThrow($restoreFailure);
    $migration = require database_path('migrations/'.$filename);

    try {
        $migration->up();
        test()->fail('The original migration failure must propagate.');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($original);
    }

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception === $restoreFailure);
})->with([
    '2026_10_29_122432_add_delivery_reliability_indexes.php',
    '2026_10_29_124216_add_canonical_suppression_email_unique_index.php',
]);
