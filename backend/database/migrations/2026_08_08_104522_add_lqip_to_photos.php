<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->text('lqip_encrypted')->nullable()->after('thumbnail_path');
            $table->string('iv_lqip', 32)->nullable()->after('lqip_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn(['lqip_encrypted', 'iv_lqip']);
        });
    }
};
