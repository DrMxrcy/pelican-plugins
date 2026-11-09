<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pp_players', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            // Null until a UUID is known (Query names without a Ping sample or usercache entry).
            $table->string('uuid', 36)->nullable();
            $table->string('name', 32);
            $table->json('previous_names')->nullable();
            // dateTime, not timestamp: MySQL/MariaDB can give the first non-null timestamp column
            // an implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            // Closed sessions only; an open session is added live by the UI.
            $table->unsignedBigInteger('total_seconds')->default(0);

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            // Several name-only rows (uuid null) may coexist; every database treats NULLs as distinct here.
            $table->unique(['server_id', 'uuid']);
            $table->index(['server_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pp_players');
    }
};
