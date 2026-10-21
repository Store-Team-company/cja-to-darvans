<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (!Schema::hasColumn('bdp_products', 'shofy_variations_count')) {
                $table->unsignedInteger('shofy_variations_count')->nullable()->after('shofy_pushed_at');
            }
            if (!Schema::hasColumn('bdp_products', 'shofy_push_note')) {
                $table->text('shofy_push_note')->nullable()->after('shofy_variations_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (Schema::hasColumn('bdp_products', 'shofy_push_note')) {
                $table->dropColumn('shofy_push_note');
            }
            if (Schema::hasColumn('bdp_products', 'shofy_variations_count')) {
                $table->dropColumn('shofy_variations_count');
            }
        });
    }
};
