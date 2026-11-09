<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pp_samples', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            // Start of the poll slot, so a retried or duplicated job overwrites its own row.
            // dateTime, not timestamp: no implicit ON UPDATE CURRENT_TIMESTAMP on MySQL/MariaDB.
            $table->dateTime('polled_at');
            $table->string('status', 16);
            $table->unsignedInteger('online')->nullable();
            $table->unsignedInteger('max')->nullable();
            $table->json('players')->nullable();
            $table->string('source', 8)->nullable();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->unique(['server_id', 'polled_at']);
            // The daily prune deletes by age across all servers.
            $table->index('polled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pp_samples');
    }
};
