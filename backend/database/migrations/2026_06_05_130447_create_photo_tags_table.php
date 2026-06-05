<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_tags', function (Blueprint $table) {
            $table->foreignId('photo_id')->constrained('photos')->onDelete('cascade');
            $table->foreignId('tag_id')->constrained('tags')->onDelete('cascade');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['photo_id', 'tag_id']);
        });

        Schema::table('photo_tags', function (Blueprint $table) {
            $table->index('photo_id', 'idx_photo_tags_photo_id');
            $table->index('tag_id', 'idx_photo_tags_tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_tags');
    }
};
