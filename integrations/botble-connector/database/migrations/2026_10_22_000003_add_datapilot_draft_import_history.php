<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datapilot_connector_drafts', function (Blueprint $table): void {
            $table->unsignedInteger('import_attempts')->default(0);
            $table->text('last_import_error')->nullable();
            $table->timestamp('last_import_attempt_at')->nullable();
            $table->timestamp('imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('datapilot_connector_drafts', function (Blueprint $table): void {
            $table->dropColumn([
                'import_attempts',
                'last_import_error',
                'last_import_attempt_at',
                'imported_at',
            ]);
        });
    }
};
