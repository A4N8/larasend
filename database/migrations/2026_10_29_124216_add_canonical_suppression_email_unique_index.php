<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'suppressions_project_normalized_email_unique';

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $index = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema()', [self::INDEX]);

            if ($index?->indisvalid) {
                return;
            }

            if ($index !== null) {
                DB::statement('DROP INDEX CONCURRENTLY '.self::INDEX);
            }
        } elseif (Schema::hasIndex('suppressions', self::INDEX)) {
            return;
        }

        $expression = $this->normalizedEmailExpression($driver);
        $groupExpression = in_array($driver, ['mysql', 'mariadb'], true) ? 'CAST('.$expression.' AS BINARY)' : $expression;
        $groups = DB::table('suppressions')->select('project_id')->groupBy('project_id')
            ->groupByRaw($groupExpression)->havingRaw('COUNT(*) > 1');
        $collisions = DB::query()->fromSub($groups, 'canonical_collisions')->count();

        if ($collisions > 0) {
            throw new RuntimeException("{$collisions} canonical suppression collision group(s) require a separate preservation plan. No rows were changed.");
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            $online = $driver === 'pgsql' ? ' CONCURRENTLY' : '';
            DB::statement('CREATE UNIQUE INDEX'.$online.' '.self::INDEX.' ON suppressions (project_id, '.$expression.')');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `suppressions` ADD COLUMN `email_normalized` VARBINARY(1020) GENERATED ALWAYS AS (CAST('.$expression.' AS BINARY)) STORED, ADD UNIQUE INDEX `'.self::INDEX.'` (`project_id`, `email_normalized`), DROP INDEX `suppressions_project_id_email_unique`');

            return;
        }

        if ($driver === 'sqlsrv') {
            DB::transaction(function () use ($expression): void {
                Schema::table('suppressions', function (Blueprint $table) use ($expression): void {
                    $table->computed('email_normalized', $expression)->persisted();
                    $table->unique(['project_id', 'email_normalized'], self::INDEX);
                    $table->dropUnique('suppressions_project_id_email_unique');
                });
            });

            return;
        }

        throw new RuntimeException("Unsupported suppression database driver [{$driver}].");
    }

    private function normalizedEmailExpression(string $driver): string
    {
        $expression = $driver === 'sqlsrv' ? 'TRIM(email COLLATE Latin1_General_100_BIN2)' : 'TRIM(email)';

        foreach (range('A', 'Z') as $uppercase) {
            $expression = sprintf("REPLACE(%s, '%s', '%s')", $expression, $uppercase, strtolower($uppercase));
        }

        return $expression;
    }
};
