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
        Schema::create('family_users', function (Blueprint $table) {
            $table->foreignId('family_id')->constrained('families')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('role', 20)->default('member');
            $table->timestamp('joined_at')->useCurrent();
            $table->primary(['family_id', 'user_id']);
        });

        Schema::table('family_users', function (Blueprint $table) {
            $table->index('user_id', 'idx_family_users_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_users');
    }
};
