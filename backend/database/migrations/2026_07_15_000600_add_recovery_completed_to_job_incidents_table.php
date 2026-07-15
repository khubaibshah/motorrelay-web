<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_incidents', function (Blueprint $table) {
            $table->foreignId('recovery_completed_by_id')->nullable()->after('recovery_sent_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('recovery_completed_at')->nullable()->after('recovery_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('job_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recovery_completed_by_id');
            $table->dropColumn('recovery_completed_at');
        });
    }
};
