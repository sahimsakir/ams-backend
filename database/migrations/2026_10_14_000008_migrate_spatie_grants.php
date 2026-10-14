<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['roles', 'permissions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('guard_name')->default('api');
                $t->string('display_name')->nullable();
            });
            $rows = DB::table($table)->get();
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['display_name' => $row->name, 'name' => '__migration_'.Str::uuid()]);
            }
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['name' => $row->slug]);
            }
        }
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $table => $column) {
            Schema::create($table, function (Blueprint $t) use ($column) {
                $t->foreignId($column)->constrained($column === 'role_id' ? 'roles' : 'permissions')->cascadeOnDelete();
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->index(['model_id', 'model_type']);
                $t->primary([$column, 'model_id', 'model_type']);
            });
        }
        foreach (DB::table('users')->whereNotNull('role_id')->get() as $u) {
            DB::table('model_has_roles')->insert(['role_id' => $u->role_id, 'model_id' => $u->id, 'model_type' => User::class]);
        }
        foreach (DB::table('user_permission')->get() as $g) {
            DB::table('model_has_permissions')->insert(['permission_id' => $g->permission_id, 'model_id' => $g->user_id, 'model_type' => User::class]);
        }
        DB::table('access_tokens')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('user_permission')->delete();
        foreach (DB::table('model_has_permissions')->where('model_type', User::class)->get() as $g) {
            DB::table('user_permission')->insert(['user_id' => $g->model_id, 'permission_id' => $g->permission_id]);
        }
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        foreach (['roles', 'permissions'] as $table) {
            $rows = DB::table($table)->get();
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['name' => '__migration_'.Str::uuid()]);
            }
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update(['name' => $row->display_name]);
            }Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['guard_name', 'display_name']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
