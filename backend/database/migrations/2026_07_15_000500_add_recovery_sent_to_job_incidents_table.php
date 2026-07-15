<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_incidents', function (Blueprint $table) {
            $table->foreignId('recovery_sent_by_id')->nullable()->after('reported_by_id')->constrained('users')->nullOnDelete();
            $table->timestamp('recovery_sent_at')->nullable()->after('recovery_required');
        });
    }

    public function down(): void
    {
        Schema::table('job_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recovery_sent_by_id');
            $table->dropColumn('recovery_sent_at');
        });
    }
};
