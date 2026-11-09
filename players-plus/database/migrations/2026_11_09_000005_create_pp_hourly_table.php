<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pp_hourly', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            // UTC start of the hour. dateTime, not timestamp: no implicit ON UPDATE on MySQL/MariaDB.
            $table->dateTime('hour_start');
            // Whole hours since the Unix epoch (hour_start / 3600). Lets the heatmap group by
            // weekday and hour with plain integer arithmetic on every database driver.
            $table->unsignedInteger('epoch_hour');
            // Over online samples only; null when the hour had none (offline or unreachable).
            $table->double('avg_online')->nullable();
            $table->unsignedInteger('max_online')->nullable();
            // Every sample in the hour, and how many of them were online.
            $table->unsignedInteger('samples');
            $table->unsignedInteger('online_samples');

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->unique(['server_id', 'hour_start']);
            // The daily prune deletes by age across all servers.
            $table->index('hour_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pp_hourly');
    }
};
