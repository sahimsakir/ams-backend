<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('slug')->unique();
            $t->boolean('system')->default(false);
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->boolean('system')->default(false);
            $t->timestamps();
        });
        Schema::create('role_permission', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('user_permission', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'permission_id']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('role_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('team_user', fn (Blueprint $t) => $t->foreignId('team_role_id')->nullable()->constrained('roles')->restrictOnDelete());
        foreach (['dashboard.view', 'attendance.view', 'attendance.record', 'applications.view', 'applications.create', 'applications.review', 'applications.manage', 'teams.view', 'teams.manage', 'users.view', 'users.manage', 'users.import', 'face.manage', 'leave_types.view', 'leave_types.manage', 'calendar.view', 'calendar.manage', 'audit.view', 'work_slots.manage', 'access.manage'] as $slug) {
            DB::table('permissions')->insert(['slug' => $slug, 'name' => ucwords(str_replace(['.', '_'], ' ', $slug)), 'system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['owner' => 'Owner', 'manager' => 'Management', 'pm' => 'Project Manager', 'team_lead' => 'Team Lead', 'employee' => 'Members'] as $slug => $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'slug' => $slug, 'system' => true, 'created_at' => now(), 'updated_at' => now()]);
            $keys = $slug === 'owner' ? ['dashboard.view', 'attendance.view', 'attendance.record', 'applications.view', 'applications.create', 'applications.review', 'applications.manage', 'teams.view', 'teams.manage', 'users.view', 'users.manage', 'users.import', 'face.manage', 'leave_types.view', 'leave_types.manage', 'calendar.view', 'calendar.manage', 'audit.view', 'work_slots.manage', 'access.manage'] : ['dashboard.view', 'attendance.view', 'attendance.record', 'applications.view', 'applications.create', 'teams.view', 'leave_types.view', 'calendar.view'];
            if (in_array($slug, ['manager', 'pm', 'team_lead'])) {
                $keys[] = 'applications.review';
            }
            foreach (DB::table('permissions')->whereIn('slug', $keys)->pluck('id') as $pid) {
                DB::table('role_permission')->insert(['role_id' => $id, 'permission_id' => $pid]);
            }
            DB::table('users')->where('role', $slug)->update(['role_id' => $id]);
            DB::table('team_user')->where('team_role', $slug)->update(['team_role_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('team_user', fn (Blueprint $t) => $t->dropConstrainedForeignId('team_role_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('role_id'));
        foreach (['user_permission', 'role_permission', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
