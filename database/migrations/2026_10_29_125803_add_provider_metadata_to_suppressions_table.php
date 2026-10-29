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
                $this->addColumn();
            });

            return;
        }

        $this->addColumn();
    }

    private function addColumn(): void
    {
        Schema::table('suppressions', function (Blueprint $table): void {
            $table->json('provider_metadata')->nullable();
        });
    }
};
