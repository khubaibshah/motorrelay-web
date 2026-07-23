<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_identity_verification_session_id')->nullable()->index();
            $table->string('stripe_identity_status')->default('unverified')->index();
            $table->timestamp('stripe_identity_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['stripe_identity_verification_session_id']);
            $table->dropIndex(['stripe_identity_status']);
            $table->dropColumn(['stripe_identity_verification_session_id', 'stripe_identity_status', 'stripe_identity_verified_at']);
        });
    }
};
