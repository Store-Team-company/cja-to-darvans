<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bdp_category_mappings')) {
            Schema::create('bdp_category_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('source_category', 500)->unique();
                $table->string('balenco_category_id')->nullable()->index();
                $table->string('balenco_category_name')->nullable();
                $table->string('balenco_category_path', 700)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_category_mappings');
    }
};
