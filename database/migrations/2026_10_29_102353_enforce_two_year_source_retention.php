<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sources')
            ->where('retention_days', '>', 0)
            ->where('retention_days', '<', 731)
            ->update(['retention_days' => 731]);

        Schema::table('sources', function (Blueprint $table): void {
            $table->unsignedInteger('retention_days')->default(731)->change();
        });
    }
};
