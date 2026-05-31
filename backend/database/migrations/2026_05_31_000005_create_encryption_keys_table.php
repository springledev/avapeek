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
        Schema::create('encryption_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('encrypted_dek');
            $table->integer('key_version')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['child_id', 'user_id', 'key_version']);
        });

        Schema::table('encryption_keys', function (Blueprint $table) {
            $table->index(['child_id', 'user_id'], 'idx_encryption_keys_child_user');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('encryption_keys');
    }
};
