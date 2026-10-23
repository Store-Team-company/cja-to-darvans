<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_botble_product_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('saas_workspaces')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('saas_products')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('saas_botble_stores')->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('source_hash', 64);
            $table->longText('payload_json'); // private reviewed payload, no credentials
            $table->string('status', 25)->default('sending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->unsignedBigInteger('remote_draft_id')->nullable();
            $table->string('last_error_code', 50)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'store_id']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_botble_product_deliveries');
    }
};
