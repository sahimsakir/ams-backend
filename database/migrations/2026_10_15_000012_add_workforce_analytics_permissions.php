<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['analytics.view_daily', 'analytics.view_monthly', 'analytics.view_team', 'analytics.view_all'] as $key) {
            DB::table('permissions')->insertOrIgnore(['name' => $key, 'slug' => $key, 'display_name' => ucwords(str_replace(['.', '_'], ' ', $key)), 'guard_name' => 'api', 'system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['analytics.view_daily', 'analytics.view_monthly', 'analytics.view_team', 'analytics.view_all'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
