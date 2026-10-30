<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('larasend_installation', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('instance_id')->unique();
            $table->timestamp('created_at');
        });

        DB::table('larasend_installation')->insert([
            'id' => 1,
            'instance_id' => Str::uuid()->toString(),
            'created_at' => now(),
        ]);
    }
};
