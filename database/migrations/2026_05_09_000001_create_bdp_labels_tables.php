<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('bdp_labels')) {
            Schema::create('bdp_labels', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('color', 30)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bdp_label_product')) {
            Schema::create('bdp_label_product', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('label_id');
                $table->unsignedBigInteger('product_id');
                $table->timestamps();
                $table->unique(['label_id', 'product_id']);
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_label_product');
        Schema::dropIfExists('bdp_labels');
    }
};
