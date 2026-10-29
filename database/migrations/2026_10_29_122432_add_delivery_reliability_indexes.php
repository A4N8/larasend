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
                    // Preserve the original migration error even if reporting is unavailable.
                }
            }
        }
    }

    private function createIndexes(): void
    {
        $indexes = [
            ['emails', 'emails_project_id_idempotency_key_unique', ['project_id', 'idempotency_key'], true],
            ['email_events', 'email_events_provider_event_id_unique', ['provider_event_id'], true],
            ['emails', 'emails_status_created_at_id_index', ['status', 'created_at', 'id'], false],
        ];

        foreach ($indexes as [$tableName, $indexName, $columns, $unique]) {
            if (DB::getDriverName() === 'pgsql') {
                $index = DB::selectOne(
                    'select i.indisvalid from pg_index i join pg_class c on c.oid = i.indexrelid join pg_namespace n on n.oid = c.relnamespace where c.relname = ? and n.nspname = current_schema()',
                    [$indexName],
                );

                if ($index?->indisvalid) {
                    continue;
                }

                if ($index !== null) {
                    DB::statement('drop index concurrently "'.$indexName.'"');
                }
            } elseif (Schema::hasIndex($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName, $unique): void {
                if ($unique) {
                    $table->unique($columns, $indexName)->online();

                    return;
                }

                $table->index($columns, $indexName)->online();
            });
        }
    }
};
