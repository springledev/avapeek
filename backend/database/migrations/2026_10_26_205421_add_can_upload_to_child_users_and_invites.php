<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `can_upload` lets a plain member upload photos and manage tags. Owners and admins
     * always can. Existing members keep today's behaviour, so the default is true.
     */
    public function up(): void
    {
        Schema::table('child_users', function (Blueprint $table) {
            $table->boolean('can_upload')->default(true)->after('relationship');
        });

        Schema::table('invites', function (Blueprint $table) {
            $table->boolean('can_upload')->default(true)->after('relationship');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invites', function (Blueprint $table) {
            $table->dropColumn('can_upload');
        });

        Schema::table('child_users', function (Blueprint $table) {
            $table->dropColumn('can_upload');
        });
    }
};
