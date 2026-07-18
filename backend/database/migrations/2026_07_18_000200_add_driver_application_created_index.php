<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'applications_driver_created_index';

    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->index(['driver_id', 'created_at'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
