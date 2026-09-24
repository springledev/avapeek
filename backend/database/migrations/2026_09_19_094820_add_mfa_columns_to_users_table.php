<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret_encrypted')->nullable()->after('public_key');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_secret_encrypted');
            $table->unsignedBigInteger('totp_last_timestep')->nullable()->after('totp_confirmed_at');
            $table->text('mfa_recovery_codes_hash')->nullable()->after('totp_last_timestep');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_secret_encrypted', 'totp_confirmed_at', 'totp_last_timestep', 'mfa_recovery_codes_hash']);
        });
    }
};
