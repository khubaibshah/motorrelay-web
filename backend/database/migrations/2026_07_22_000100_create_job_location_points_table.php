<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_location_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->decimal('speed_kph', 7, 2)->nullable();
            $table->string('source', 30)->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
            $table->index(['job_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_location_points');
    }
};
