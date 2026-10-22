<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bdp_customer_admin_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('saas_workspaces')->cascadeOnDelete();
            $table->string('action', 60);
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->string('actor', 120);
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_customer_admin_audit');
    }
};
