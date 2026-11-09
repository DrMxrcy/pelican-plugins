<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mp_installs', function (Blueprint $table) {
            // Plan 36 update checks: the newest version for the installed pack's loader and
            // Minecraft version, whether it is newer, and the version the owner was told about.
            $table->string('latest_version_id', 64)->nullable()->after('version_number');
            $table->string('latest_version_number')->nullable()->after('latest_version_id');
            $table->boolean('update_available')->default(false)->after('latest_version_number');
            $table->string('notified_version', 64)->nullable()->after('update_available');
            $table->timestamp('update_checked_at')->nullable()->after('notified_version');
        });
    }

    public function down(): void
    {
        Schema::table('mp_installs', function (Blueprint $table) {
            $table->dropColumn(['latest_version_id', 'latest_version_number', 'update_available', 'notified_version', 'update_checked_at']);
        });
    }
};
