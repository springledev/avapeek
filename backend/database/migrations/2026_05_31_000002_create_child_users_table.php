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
        Schema::create('child_users', function (Blueprint $table) {
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('role', 20)->default('member');
            $table->string('relationship', 30)->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->primary(['child_id', 'user_id']);
        });

        Schema::table('child_users', function (Blueprint $table) {
            $table->index('user_id', 'idx_child_users_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('child_users');
    }
};
