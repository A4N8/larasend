<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('does not wrap implicit commit DDL in a manual transaction', function (string $driver): void {
    Schema::swap(Schema::getFacadeRoot());
    DB::shouldReceive('getDriverName')->andReturn($driver);
    DB::shouldReceive('transaction')->never();
    Schema::shouldReceive('table')->with('emails', Mockery::type(Closure::class))->once();
    Schema::shouldReceive('table')->with('email_events', Mockery::type(Closure::class))->once();

    $migration = require database_path('migrations/2026_10_30_102933_add_event_publication_fields.php');
    $migration->up();
})->with(['mysql', 'mariadb']);

it('filters nullable unique publication indexes for SQL Server legacy rows', function (): void {
    $statements = [];
    $connection = new SqlServerConnection(fn () => throw new RuntimeException('No SQL Server network connection is allowed.'));
    $connection->useDefaultSchemaGrammar();
    Schema::swap(Schema::getFacadeRoot());
    DB::shouldReceive('getDriverName')->andReturn('sqlsrv');
    DB::shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$statements): bool {
        $statements[] = strtolower($sql);

        return true;
    });
    Schema::shouldReceive('hasIndex')->andReturn(false);
    Schema::shouldReceive('table')->andReturnUsing(function (string $table, Closure $callback) use ($connection, &$statements): void {
        $blueprint = new Blueprint($connection, $table, $callback);
        $statements = [...$statements, ...array_map(strtolower(...), $blueprint->toSql())];
    });
    $migration = require database_path('migrations/2026_10_30_102934_add_event_publication_indexes.php');
    $migration->up();

    $unique = array_values(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'create unique index')));
    expect($unique)->toHaveCount(2)
        ->and($unique[0])->toContain('where [email_id] is not null and [email_sequence] is not null')
        ->and($unique[1])->toContain('where [submission_correlation_id] is not null');
});
