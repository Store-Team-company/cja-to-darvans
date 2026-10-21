<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bdp_product_reviews')) {
            return;
        }

        Schema::create('bdp_product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bdp_product_id')->constrained('bdp_products')->cascadeOnDelete();
            $table->string('source')->default('cj')->index();
            $table->string('source_review_id')->nullable()->index();
            $table->string('source_product_id')->nullable()->index();
            $table->unsignedTinyInteger('score')->nullable()->index();
            $table->string('reviewer_name')->nullable();
            $table->longText('review_text')->nullable();
            $table->timestamp('review_date')->nullable();
            $table->string('country_code')->nullable();
            $table->string('flag_icon_url')->nullable();
            $table->json('review_images')->nullable();
            $table->boolean('is_approved')->default(false)->index();
            $table->boolean('is_pushed')->default(false)->index();
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_review_id'], 'bdp_reviews_source_review_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_product_reviews');
    }
};
