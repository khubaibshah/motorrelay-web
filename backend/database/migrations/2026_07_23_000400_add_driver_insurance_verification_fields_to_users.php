<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('driver_insurance_status')->default('not_started')->index();
            $table->string('driver_insurance_provider')->nullable();
            $table->text('driver_insurance_policy_number')->nullable();
            $table->date('driver_insurance_expires_at')->nullable();
            $table->string('driver_insurance_document_path')->nullable();
            $table->timestamp('driver_insurance_submitted_at')->nullable();
            $table->timestamp('driver_insurance_verified_at')->nullable();
            $table->timestamp('driver_insurance_last_checked_at')->nullable();
            $table->json('driver_insurance_check_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['driver_insurance_status']);
            $table->dropColumn([
                'driver_insurance_status', 'driver_insurance_provider', 'driver_insurance_policy_number',
                'driver_insurance_expires_at', 'driver_insurance_document_path', 'driver_insurance_submitted_at',
                'driver_insurance_verified_at', 'driver_insurance_last_checked_at', 'driver_insurance_check_result',
            ]);
        });
    }
};
