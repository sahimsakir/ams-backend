<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'team_lead')->value('id');
        $permission = DB::table('permissions')->where('name', 'attendance.photo_view_team')->value('id');
        if ($role && $permission) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve explicit administrator grants when rolling back.
    }
};
