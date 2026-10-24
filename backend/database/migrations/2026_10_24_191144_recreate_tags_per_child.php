<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags become per-child objects whose name is encrypted with the child's DEK.
 * There is no live data, so the old plaintext tables are dropped and recreated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('photo_tags');
        Schema::dropIfExists('tags');

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('name_encrypted');
            $table->string('iv_name', 32);
            $table->timestamp('created_at')->useCurrent();
            $table->index('child_id', 'idx_tags_child_id');
        });

        Schema::create('photo_tags', function (Blueprint $table) {
            $table->foreignId('photo_id')->constrained('photos')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['photo_id', 'tag_id']);
            $table->index('tag_id', 'idx_photo_tags_tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_tags');
        Schema::dropIfExists('tags');
    }
};
