<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Admin approval is NOT proof that the mailbox is controlled by the user.
            $table->timestamp('saas_manually_approved_at')->nullable();
            $table->boolean('saas_password_change_required')->default(false);
        });

        Schema::create('saas_customer_admin_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('actor', 60); // Existing authenticated platform admin session
            $table->string('event', 50);
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_customer_admin_events');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['saas_manually_approved_at', 'saas_password_change_required']);
        });
    }
};
