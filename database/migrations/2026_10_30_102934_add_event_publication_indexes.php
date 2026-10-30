<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->createIndexes();

            return;
        }

        $previousTimeout = DB::scalar('SHOW lock_timeout');
        $failure = null;

        try {
            DB::statement("SET lock_timeout = '5s'");
            $this->createIndexes();
        } catch (Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            try {
                DB::selectOne("SELECT set_config('lock_timeout', ?, false)", [$previousTimeout]);
            } catch (Throwable $restoreException) {
                if ($failure === null) {
                    throw $restoreException;
                }

                try {
                    report($restoreException);
                } catch (Throwable) {
                    // Preserve the original migration error when reporting also fails.
                }
            }
        }
    }

    private function createIndexes(): void
    {
        $indexes = [
            ['email_events_email_id_email_sequence_unique', ['email_id', 'email_sequence'], true],
            ['email_events_submission_correlation_id_unique', ['submission_correlation_id'], true],
            ['email_events_email_id_id_index', ['email_id', 'id'], false],
            ['email_events_source_id_ses_message_id_index', ['source_id', 'ses_message_id'], false],
        ];

        foreach ($indexes as [$name, $columns, $unique]) {
            if (DB::getDriverName() === 'pgsql') {
                $index = DB::selectOne(
                    'select i.indisvalid from pg_index i join pg_class c on c.oid = i.indexrelid join pg_namespace n on n.oid = c.relnamespace where c.relname = ? and n.nspname = current_schema()',
                    [$name],
                );

                if ($index?->indisvalid) {
                    continue;
                }

                if ($index !== null) {
                    DB::statement('drop index concurrently "'.$name.'"');
                }
            } elseif (Schema::hasIndex('email_events', $name)) {
                continue;
            }

            if (DB::getDriverName() === 'sqlsrv' && $unique) {
                $wrapped = array_map(fn (string $column): string => '['.$column.']', $columns);
                $conditions = array_map(fn (string $column): string => $column.' IS NOT NULL', $wrapped);
                DB::statement('CREATE UNIQUE INDEX ['.$name.'] ON [email_events] ('.implode(', ', $wrapped).') WHERE '.implode(' AND ', $conditions).' WITH (ONLINE = ON)');

                continue;
            }

            Schema::table('email_events', function (Blueprint $table) use ($name, $columns, $unique): void {
                if ($unique) {
                    $table->unique($columns, $name)->online();

                    return;
                }

                $table->index($columns, $name)->online();
            });
        }
    }
};
