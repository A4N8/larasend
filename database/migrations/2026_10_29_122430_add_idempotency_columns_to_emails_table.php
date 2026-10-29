<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '5s'");
                $this->addColumns();
            });

            return;
        }

        $this->addColumns();
    }

    private function addColumns(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable();
            $table->string('idempotency_hash', 64)->nullable();
        });
    }
};
