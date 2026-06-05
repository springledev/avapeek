<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('child_id')->constrained('children')->onDelete('cascade');
            $table->string('name', 100);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['child_id', 'name']);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->index('child_id', 'idx_tags_child_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
