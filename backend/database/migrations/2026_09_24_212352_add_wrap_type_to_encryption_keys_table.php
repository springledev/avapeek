<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 'kek' rows are wrapped with the owner's password-derived KEK;
     * 'public_key' rows were shared by another member and still need the
     * owner to re-wrap them with their KEK.
     */
    public function up(): void
    {
        Schema::table('encryption_keys', function (Blueprint $table) {
            $table->string('wrap_type', 16)->default('kek')->after('encrypted_dek');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('encryption_keys', function (Blueprint $table) {
            $table->dropColumn('wrap_type');
        });
    }
};
