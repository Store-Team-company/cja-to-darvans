<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bdp_settings')) {
            Schema::create('bdp_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bdp_balenco_categories')) {
            Schema::create('bdp_balenco_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('balenco_category_id')->unique();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->string('name');
                $table->string('path')->nullable()->index();
                $table->string('status')->nullable()->index();
                $table->json('raw_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_balenco_categories');
        Schema::dropIfExists('bdp_settings');
    }
};
