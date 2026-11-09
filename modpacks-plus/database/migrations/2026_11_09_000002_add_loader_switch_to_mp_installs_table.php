<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mp_installs', function (Blueprint $table) {
            // Target of a loader switch (loader, Minecraft version, egg, image, variables).
            $table->json('loader_switch')->nullable()->after('plan');
            // The server's egg, startup, image, variables and mounts before the switch.
            $table->json('snapshot')->nullable()->after('loader_switch');
            // Where worlds and player files wait while the server reinstalls.
            $table->string('preserved_path')->nullable()->after('moved_aside_path');
            $table->timestamp('restore_requested_at')->nullable()->after('finished_at');
            $table->timestamp('restored_at')->nullable()->after('restore_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('mp_installs', function (Blueprint $table) {
            $table->dropColumn(['loader_switch', 'snapshot', 'preserved_path', 'restore_requested_at', 'restored_at']);
        });
    }
};
