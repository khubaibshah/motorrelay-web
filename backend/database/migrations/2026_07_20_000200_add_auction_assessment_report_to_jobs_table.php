<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('auction_assessment_report_path')->nullable()->after('auction_reference');
            $table->string('auction_assessment_report_disk')->nullable()->after('auction_assessment_report_path');
            $table->string('auction_assessment_report_name')->nullable()->after('auction_assessment_report_disk');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn([
                'auction_assessment_report_path',
                'auction_assessment_report_disk',
                'auction_assessment_report_name',
            ]);
        });
    }
};
