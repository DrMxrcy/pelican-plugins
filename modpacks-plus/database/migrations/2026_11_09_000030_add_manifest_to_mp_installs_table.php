<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mp_installs', function (Blueprint $table) {
            // The files this install put on the server: [{path, size, hash, kept}] (plan 36).
            $table->json('manifest')->nullable()->after('plan');
            // install | switch | update (plan 36).
            $table->string('mode', 16)->default('install')->after('status');
            // The admin's choices for an update or switch: fresh_world, overwrite_changes.
            $table->json('options')->nullable()->after('mode');
            // The installed pack an update or switch replaces (its manifest is diffed).
            $table->unsignedBigInteger('previous_install_id')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('mp_installs', function (Blueprint $table) {
            $table->dropColumn(['manifest', 'mode', 'options', 'previous_install_id']);
        });
    }
};
