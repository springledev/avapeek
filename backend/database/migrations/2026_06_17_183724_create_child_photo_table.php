<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_photo', function (Blueprint $table) {
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->foreignId('photo_id')->constrained('photos')->onDelete('cascade');
            $table->text('encrypted_pek');
            $table->string('iv_pek', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['child_id', 'photo_id']);
        });

        Schema::table('child_photo', function (Blueprint $table) {
            $table->index('photo_id', 'idx_child_photo_photo_id');
            $table->index('child_id', 'idx_child_photo_child_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_photo');
    }
};
