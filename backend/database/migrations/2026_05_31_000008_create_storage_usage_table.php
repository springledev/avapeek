<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_usage', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique()->default('global');
            $table->bigInteger('used_bytes')->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });

        DB::table('storage_usage')->insert(['key' => 'global', 'used_bytes' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_usage');
    }
};
