<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
        Schema::table('emails', function (Blueprint $table): void {
            $table->bigInteger('event_sequence')->default(0);
        });

        Schema::table('email_events', function (Blueprint $table): void {
            $table->bigInteger('email_sequence')->nullable();
            $table->smallInteger('contract_version')->nullable();
            $table->json('public_event_data')->nullable();
            $table->string('submission_correlation_id', 64)->nullable();
        });
    }
};
