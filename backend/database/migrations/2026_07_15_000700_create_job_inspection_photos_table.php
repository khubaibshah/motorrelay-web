<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_inspection_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['job_id', 'sort_order']);
        });

        DB::table('jobs')
            ->whereNotNull('delivery_proof_path')
            ->orderBy('id')
            ->get(['id', 'assigned_to_id', 'delivery_proof_disk', 'delivery_proof_path', 'created_at', 'updated_at'])
            ->each(function ($job): void {
                DB::table('job_inspection_photos')->insert([
                    'job_id' => $job->id,
                    'uploaded_by_id' => $job->assigned_to_id,
                    'disk' => $job->delivery_proof_disk ?: config('invoices.proof_disk', 'local'),
                    'path' => $job->delivery_proof_path,
                    'original_name' => basename($job->delivery_proof_path),
                    'mime_type' => null,
                    'size' => null,
                    'sort_order' => 1,
                    'created_at' => $job->created_at ?? now(),
                    'updated_at' => $job->updated_at ?? now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_inspection_photos');
    }
};
