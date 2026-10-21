<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (!Schema::hasColumn('bdp_products', 'balenco_sync_status')) {
                $table->string('balenco_sync_status')->nullable()->after('shofy_push_note');
            }
            if (!Schema::hasColumn('bdp_products', 'balenco_synced_at')) {
                $table->timestamp('balenco_synced_at')->nullable()->after('balenco_sync_status');
            }
            if (!Schema::hasColumn('bdp_products', 'balenco_sync_message')) {
                $table->text('balenco_sync_message')->nullable()->after('balenco_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bdp_products', function (Blueprint $table) {
            if (Schema::hasColumn('bdp_products', 'balenco_sync_message')) {
                $table->dropColumn('balenco_sync_message');
            }
            if (Schema::hasColumn('bdp_products', 'balenco_synced_at')) {
                $table->dropColumn('balenco_synced_at');
            }
            if (Schema::hasColumn('bdp_products', 'balenco_sync_status')) {
                $table->dropColumn('balenco_sync_status');
            }
        });
    }
};
