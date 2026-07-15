<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            Schema::table('push_subscriptions', function (Blueprint $table) {
                if (! Schema::hasColumn('push_subscriptions', 'device_id')) {
                    $table->string('device_id')->nullable()->after('token');
                }

                if (! Schema::hasColumn('push_subscriptions', 'last_registered_at')) {
                    $table->timestamp('last_registered_at')->nullable()->after('device_id');
                }
            });

            return;
        }

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform', 20);
            $table->text('token');
            $table->string('device_id')->nullable();
            $table->timestamp('last_registered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
