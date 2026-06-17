<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('uploaded_by')->constrained('users');

            $table->string('original_path', 500);
            $table->string('thumbnail_path', 500);
            $table->string('iv_original', 32);
            $table->string('iv_thumbnail', 32);
            $table->string('original_mime', 50);
            $table->bigInteger('size_bytes');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->text('exif_encrypted')->nullable();
            $table->string('iv_exif', 32)->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();
        });

        $driver = DB::connection()->getDriverName();
        DB::statement('CREATE INDEX idx_photos_uploaded_by ON photos (uploaded_by)');
        DB::statement('CREATE INDEX idx_photos_created_at ON photos (created_at DESC)');
        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX idx_photos_taken_at ON photos (taken_at DESC NULLS LAST)');
        } else {
            DB::statement('CREATE INDEX idx_photos_taken_at ON photos (taken_at DESC)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
