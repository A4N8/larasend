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
        foreach (['email_recipients', 'email_events', 'email_attachments'] as $tableName) {
            $indexName = $tableName.'_email_id_index';

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
            } elseif (Schema::hasIndex($tableName, ['email_id'])) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('email_id')->online();
            });
        }
    }
};
