<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Keep the licence number encrypted; the raw one-time check code is never stored.
            $table->text('driver_licence_number')->nullable()->after('driver_dvla_code');
            $table->string('driver_dvla_check_status')->default('not_started')->index()->after('driver_licence_number');
            $table->string('driver_dvla_check_code_hash', 64)->nullable()->after('driver_dvla_check_status');
            $table->timestamp('driver_dvla_check_submitted_at')->nullable()->after('driver_dvla_check_code_hash');
            $table->timestamp('driver_dvla_verified_at')->nullable()->after('driver_dvla_check_submitted_at');
            $table->timestamp('driver_dvla_expires_at')->nullable()->after('driver_dvla_verified_at');
            $table->timestamp('driver_dvla_last_checked_at')->nullable()->after('driver_dvla_expires_at');
            $table->json('driver_dvla_check_result')->nullable()->after('driver_dvla_last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['driver_dvla_check_status']);
            $table->dropColumn([
                'driver_licence_number',
                'driver_dvla_check_status',
                'driver_dvla_check_code_hash',
                'driver_dvla_check_submitted_at',
                'driver_dvla_verified_at',
                'driver_dvla_expires_at',
                'driver_dvla_last_checked_at',
                'driver_dvla_check_result',
            ]);
        });
    }
};
