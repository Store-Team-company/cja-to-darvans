<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only plugin-owned mapping tables; the eCommerce schema is never altered.
        Schema::create('datapilot_connector_product_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pairing_id')->constrained('datapilot_connector_pairings')->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('source_product_id', 160);
            $table->unsignedBigInteger('botble_product_id');
            $table->timestamps();
            $table->unique(['pairing_id', 'source', 'source_product_id'], 'dp_product_source_unique');
            $table->unique(['pairing_id', 'botble_product_id'], 'dp_product_target_unique');
        });

        Schema::create('datapilot_connector_variant_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_mapping_id')
                ->constrained('datapilot_connector_product_mappings')->cascadeOnDelete();
            $table->string('source_variant_id', 180);
            $table->unsignedBigInteger('botble_product_id');
            $table->unsignedBigInteger('botble_variation_id');
            $table->timestamps();
            $table->unique(['product_mapping_id', 'source_variant_id'], 'dp_variant_source_unique');
            $table->unique(['product_mapping_id', 'botble_product_id'], 'dp_variant_product_unique');
            $table->unique(['product_mapping_id', 'botble_variation_id'], 'dp_variant_relation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datapilot_connector_variant_mappings');
        Schema::dropIfExists('datapilot_connector_product_mappings');
    }
};
