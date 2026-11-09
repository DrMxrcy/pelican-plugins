<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pp_log_cursors', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            // Which logs/latest.log this is: sha256 of its first line. A new hash (or a file
            // smaller than the offset) means the log rotated and the scan restarts at 0.
            $table->char('identity', 64)->nullable();
            // File size and modification time at the last scan; both unchanged skips the download.
            // Size 0 while a backlog remains (chunk cap or deadline), so the next scan reads on.
            // A log with no complete line yet is remembered with identity null and its size.
            $table->unsignedBigInteger('size')->default(0);
            // dateTime, not timestamp: no implicit ON UPDATE CURRENT_TIMESTAMP on MySQL/MariaDB.
            $table->dateTime('file_modified_at')->nullable();
            // Byte offset of the first line not yet parsed (always at a line start).
            $table->unsignedBigInteger('offset')->default(0);
            // sha256 of the line ending at the offset: a different line there is a rotation too.
            $table->char('tail', 64)->nullable();
            // Moment of the last timestamped line parsed, so the next scan dates its lines.
            $table->dateTime('clock_at')->nullable();
            // Joins still waiting for their leave line: name => {at, uuid}. Names and UUIDs only.
            $table->json('pending')->nullable();
            $table->dateTime('last_scanned_at')->nullable();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pp_log_cursors');
    }
};
