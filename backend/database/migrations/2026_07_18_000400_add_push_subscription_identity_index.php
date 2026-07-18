<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_subscriptions') || ! Schema::hasColumn('push_subscriptions', 'device_id')) {
            return;
        }

        $duplicates = DB::table('push_subscriptions')
            ->select('user_id', 'platform', 'device_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('device_id')
            ->groupBy('user_id', 'platform', 'device_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('push_subscriptions')
                ->where('user_id', $duplicate->user_id)
                ->where('platform', $duplicate->platform)
                ->where('device_id', $duplicate->device_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'platform', 'device_id'],
                'push_subscriptions_user_platform_device_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            return;
        }

        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->dropUnique('push_subscriptions_user_platform_device_unique');
        });
    }
};
