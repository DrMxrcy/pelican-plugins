<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mp_installs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('provider', 32);
            $table->string('pack_id', 64);
            $table->string('version_id', 64);
            $table->string('name');
            $table->string('version_number')->nullable();
            $table->text('icon')->nullable();
            $table->string('status', 16);
            $table->string('current_step', 32)->nullable();
            // Every hop of the job chain bumps seq; a job whose seq is stale does nothing.
            $table->unsignedInteger('seq')->default(0);
            $table->json('steps')->nullable();
            $table->json('log')->nullable();
            $table->json('plan')->nullable();
            $table->json('progress')->nullable();
            $table->json('summary')->nullable();
            $table->string('backup_uuid', 36)->nullable();
            $table->string('moved_aside_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('step_started_at')->nullable();
            $table->timestamp('step_deadline_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedTinyInteger('resumes')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['server_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mp_installs');
    }
};
