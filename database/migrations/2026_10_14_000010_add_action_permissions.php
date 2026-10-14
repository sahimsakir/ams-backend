<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', fn (Blueprint $t) => $t->boolean('deprecated')->default(false));
        $keys = ['attendance.view_team', 'attendance.view_all', 'attendance.photo_view_team', 'attendance.photo_view_all', 'applications.view_team', 'applications.cancel_team', 'profile.view_self', 'profile.view_team', 'profile.view_all', 'profile.update_self', 'profile.change_password', 'face.enroll_self', 'users.create', 'users.update', 'users.delete', 'teams.create', 'teams.members_update', 'leave_types.create', 'leave_types.update', 'leave_types.delete', 'calendar.create', 'calendar.update', 'calendar.delete', 'attendance.check_in', 'attendance.check_out', 'applications.create_leave', 'applications.create_home_office', 'applications.create_overtime', 'applications.approve', 'applications.reject', 'applications.view_all', 'applications.cancel_any', 'home_office.view', 'home_office.assign', 'home_office.cancel', 'work_slots.update_user', 'work_slots.update_team', 'face.enroll_others', 'face.reset', 'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'permissions.view', 'permissions.create', 'permissions.update', 'permissions.delete', 'users.assign_access', 'applications.document_view', 'applications.cancel_self', 'attendance.photo_view_self'];
        foreach (array_unique($keys) as $key) {
            DB::table('permissions')->insertOrIgnore(['name' => $key, 'slug' => $key, 'display_name' => ucwords(str_replace(['.', '_'], ' ', $key)), 'guard_name' => 'api', 'system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $mapping = ['users.manage' => ['users.create', 'users.update', 'users.delete'], 'teams.manage' => ['teams.create', 'teams.members_update'], 'leave_types.manage' => ['leave_types.create', 'leave_types.update', 'leave_types.delete'], 'calendar.manage' => ['calendar.create', 'calendar.update', 'calendar.delete'], 'attendance.record' => ['attendance.check_in', 'attendance.check_out'], 'applications.create' => ['applications.create_leave', 'applications.create_home_office', 'applications.create_overtime'], 'applications.review' => ['applications.approve', 'applications.reject'], 'applications.manage' => ['applications.view_all', 'applications.cancel_any'], 'home_office.manage' => ['home_office.view', 'home_office.assign', 'home_office.cancel'], 'work_slots.manage' => ['work_slots.update_user', 'work_slots.update_team'], 'face.manage' => ['face.enroll_others', 'face.reset'], 'access.manage' => ['roles.view', 'roles.create', 'roles.update', 'roles.delete', 'permissions.view', 'permissions.create', 'permissions.update', 'permissions.delete', 'users.assign_access'], 'applications.view' => ['applications.document_view', 'applications.cancel_self'], 'attendance.view' => ['attendance.photo_view_self']];
        DB::table('permissions')->whereIn('name', array_diff(array_keys($mapping), ['applications.view', 'attendance.view']))->update(['deprecated' => true]);
        foreach ($mapping as $old => $children) {
            $oldId = DB::table('permissions')->where('name', $old)->value('id');
            if (! $oldId) {
                continue;
            }
            foreach ($children as $key) {
                $id = DB::table('permissions')->where('name', $key)->value('id');
                foreach (DB::table('role_permission')->where('permission_id', $oldId)->get() as $g) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $g->role_id, 'permission_id' => $id]);
                }
                foreach (DB::table('model_has_permissions')->where('permission_id', $oldId)->get() as $g) {
                    DB::table('model_has_permissions')->insertOrIgnore(['model_id' => $g->model_id, 'model_type' => $g->model_type, 'permission_id' => $id]);
                }
            }
        }
        foreach (DB::table('roles')->pluck('id') as $role) {
            foreach (['profile.view_self', 'profile.change_password', 'face.enroll_self'] as $key) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('name', $key)->value('id')]);
            }
        }
        $lead = DB::table('roles')->where('name', 'team_lead')->value('id');
        foreach (['attendance.view_team', 'applications.view_team'] as $key) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $lead, 'permission_id' => DB::table('permissions')->where('name', $key)->value('id')]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_unique(['attendance.view_team', 'attendance.view_all', 'attendance.photo_view_team', 'attendance.photo_view_all', 'applications.view_team', 'applications.cancel_team', 'profile.view_self', 'profile.view_team', 'profile.view_all', 'profile.update_self', 'profile.change_password', 'face.enroll_self', 'users.create', 'users.update', 'users.delete', 'teams.create', 'teams.members_update', 'leave_types.create', 'leave_types.update', 'leave_types.delete', 'calendar.create', 'calendar.update', 'calendar.delete', 'attendance.check_in', 'attendance.check_out', 'applications.create_leave', 'applications.create_home_office', 'applications.create_overtime', 'applications.approve', 'applications.reject', 'applications.view_all', 'applications.cancel_any', 'home_office.view', 'home_office.assign', 'home_office.cancel', 'work_slots.update_user', 'work_slots.update_team', 'face.enroll_others', 'face.reset', 'roles.view', 'roles.create', 'roles.update', 'roles.delete', 'permissions.view', 'permissions.create', 'permissions.update', 'permissions.delete', 'users.assign_access', 'applications.document_view', 'applications.cancel_self', 'attendance.photo_view_self']))->delete();
        Schema::table('permissions', fn (Blueprint $t) => $t->dropColumn('deprecated'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
