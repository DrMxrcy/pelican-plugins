<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('pp_players')->cascadeOnDelete();
            // dateTime, not timestamp: no implicit ON UPDATE CURRENT_TIMESTAMP on MySQL/MariaDB.
            $table->dateTime('started_at');
            // Null while the player is online.
            $table->dateTime('ended_at')->nullable();
            // poll: built from consecutive samples; log: refined from the server log (plan 28).
            $table->string('source', 8)->default('poll');

            $table->index(['player_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pp_sessions');
    }
};
