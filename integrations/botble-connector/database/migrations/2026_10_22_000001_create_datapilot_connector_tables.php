<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datapilot_connector_pairings', function (Blueprint $table) {
            $table->id();
            $table->uuid('workspace_public_id');
            $table->uuid('store_public_id')->unique();
            $table->longText('shared_secret'); // encrypted Eloquent cast
            $table->string('status', 20)->default('paired');
            $table->timestamp('paired_at');
            $table->timestamps();
        });

        Schema::create('datapilot_connector_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pairing_id')->constrained('datapilot_connector_pairings')->cascadeOnDelete();
            $table->string('nonce', 64);
            $table->timestamp('expires_at');
            $table->unique(['pairing_id', 'nonce']);
        });

        Schema::create('datapilot_connector_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pairing_id')->constrained('datapilot_connector_pairings')->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('source_product_id', 160);
            $table->string('title', 250);
            $table->string('sku', 180)->nullable();
            $table->string('payload_sha256', 64);
            $table->longText('payload'); // encrypted snapshot
            $table->string('status', 24)->default('pending_review');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datapilot_connector_drafts');
        Schema::dropIfExists('datapilot_connector_nonces');
        Schema::dropIfExists('datapilot_connector_pairings');
    }
};
