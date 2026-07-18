<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->index(['posted_by_id', 'status', 'created_at'], 'jobs_posted_status_created_index');
            $table->index(['assigned_to_id', 'status', 'updated_at'], 'jobs_assigned_status_updated_index');
            $table->index(['status', 'payment_status', 'goes_live_at'], 'jobs_marketplace_visibility_index');
            $table->index(['pickup_postcode', 'status'], 'jobs_pickup_status_index');
        });

        Schema::table('job_applications', function (Blueprint $table) {
            $table->index(['job_id', 'status', 'created_at'], 'applications_job_status_created_index');
            $table->index(['driver_id', 'status', 'created_at'], 'applications_driver_status_created_index');
        });

        Schema::table('message_threads', function (Blueprint $table) {
            $table->index(['job_id', 'updated_at'], 'threads_job_updated_index');
        });

        Schema::table('message_thread_user', function (Blueprint $table) {
            $table->index(['user_id', 'message_thread_id'], 'thread_users_user_thread_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['message_thread_id', 'created_at'], 'messages_thread_created_index');
        });

        Schema::table('message_receipts', function (Blueprint $table) {
            $table->index(['user_id', 'viewed_at'], 'receipts_user_viewed_index');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['job_id', 'status', 'created_at'], 'invoices_job_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropIndex('invoices_job_status_created_index'));
        Schema::table('message_receipts', fn (Blueprint $table) => $table->dropIndex('receipts_user_viewed_index'));
        Schema::table('messages', fn (Blueprint $table) => $table->dropIndex('messages_thread_created_index'));
        Schema::table('message_thread_user', fn (Blueprint $table) => $table->dropIndex('thread_users_user_thread_index'));
        Schema::table('message_threads', fn (Blueprint $table) => $table->dropIndex('threads_job_updated_index'));
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropIndex('applications_job_status_created_index');
            $table->dropIndex('applications_driver_status_created_index');
        });
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex('jobs_posted_status_created_index');
            $table->dropIndex('jobs_assigned_status_updated_index');
            $table->dropIndex('jobs_marketplace_visibility_index');
            $table->dropIndex('jobs_pickup_status_index');
        });
    }
};
