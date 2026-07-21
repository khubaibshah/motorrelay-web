<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assigned_to_id');
        });

        // Preserve a sensible deadline for jobs assigned before this field existed.
        DB::table('jobs')
            ->whereNotNull('assigned_to_id')
            ->whereNull('assigned_at')
            ->update(['assigned_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('assigned_at');
        });
    }
};
