<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Original filename, encrypted client-side with the photo PEK.
     */
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->text('name_encrypted')->nullable();
            $table->string('iv_name', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn(['name_encrypted', 'iv_name']);
        });
    }
};
