<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_workspace_billing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained('saas_workspaces')->cascadeOnDelete();
            $table->string('stripe_customer_id', 128)->nullable()->unique();
            $table->string('stripe_subscription_id', 128)->nullable()->unique();
            $table->string('stripe_price_id', 128)->nullable();
            $table->string('pending_checkout_id', 128)->nullable();
            $table->longText('pending_checkout_url')->nullable();
            $table->timestamp('pending_checkout_expires_at')->nullable();
            $table->string('plan_code', 24)->nullable();
            $table->string('status', 30)->default('incomplete');
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedBigInteger('last_event_created')->default(0);
            $table->timestamps();
        });

        Schema::create('saas_stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id', 180)->unique();
            $table->string('event_type', 120);
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_stripe_events');
        Schema::dropIfExists('saas_workspace_billing');
    }
};
