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
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->foreignId('uploaded_by')->constrained('users');

            // Encrypted file paths in MinIO
            $table->string('original_path', 500);
            $table->string('thumbnail_path', 500)->nullable();

            // Encryption metadata
            $table->string('iv_original', 32);
            $table->string('iv_thumbnail', 32)->nullable();

            // File metadata
            $table->string('mime_type', 50);
            $table->bigInteger('size_bytes');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();

            // Encrypted EXIF
            $table->text('exif_encrypted')->nullable();
            $table->string('iv_exif', 32)->nullable();

            // Thumbnail processing status
            $table->string('thumbnail_status', 20)->default('pending');

            // Timestamps
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->index('child_id', 'idx_photos_child_id');
            $table->index('thumbnail_status', 'idx_photos_thumbnail_status');
            $table->index('created_at', 'idx_photos_created_at');
            $table->index('taken_at', 'idx_photos_taken_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
