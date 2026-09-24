<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores the user's RSA private key, wrapped client-side with their KEK,
     * so DEKs shared to their public key can be opened on any device.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('encrypted_private_key')->nullable()->after('public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('encrypted_private_key');
        });
    }
};
