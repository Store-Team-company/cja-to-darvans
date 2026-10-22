<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bdp_admin_profiles')) {
            Schema::create('bdp_admin_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->default('Admin');
                $table->string('email')->nullable();
                $table->string('username')->unique()->default('admin');
                $table->string('password')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('bdp_admin_profiles') && DB::table('bdp_admin_profiles')->count() === 0) {
            DB::table('bdp_admin_profiles')->insert([
                'name' => 'Admin',
                'email' => null,
                'username' => env('BDP_ADMIN_USERNAME', 'admin'),
                'password' => (string) env('BDP_LOGIN_PASSWORD_HASH', '') ?: (env('BDP_ADMIN_PASSWORD') ? Hash::make(env('BDP_ADMIN_PASSWORD')) : Hash::make(\Illuminate\Support\Str::random(48))),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bdp_admin_profiles');
    }
};
