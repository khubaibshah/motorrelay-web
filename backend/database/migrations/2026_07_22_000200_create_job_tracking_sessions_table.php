<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_tracking_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'ended_at']);
            $table->index(['driver_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_tracking_sessions');
    }
};
