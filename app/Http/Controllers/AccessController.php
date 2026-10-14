<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccessController extends Controller
{
    private function owner(Request $r): void
    {
        abort_unless($r->user()->isOwner(), 403, 'Only Owner can manage access.');
    }

    public function index(Request $r)
    {
        abort_unless($r->user()->isOwner() || $r->user()->hasPermission('teams.manage') || $r->user()->hasPermission('users.manage'), 403);

        return Role::with('permissions')->orderBy('id')->get();
    }

    public function permissions(Request $r)
    {
        $this->owner($r);

        return Permission::orderBy('slug')->get();
    }

    public function store(Request $r)
    {
        return $this->saveRole($r);
    }

    public function update(Request $r, int $id)
    {
        return $this->saveRole($r, $id);
    }

    private function saveRole(Request $r, ?int $id = null)
    {
        $this->owner($r);
        $d = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('roles', 'display_name')->ignore($id)], 'slug' => ['required', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100', Rule::unique('roles', 'slug')->ignore($id)], 'permission_ids' => 'present|array', 'permission_ids.*' => 'integer|distinct|exists:permissions,id']);

        return DB::transaction(function () use ($r, $id, $d) {
            $role = $id ? Role::lockForUpdate()->findOrFail($id) : new Role;
            if ($role->exists && $role->slug === 'owner') {
                abort(422, 'Owner is protected and always has all permissions.');
            }
            abort_if($role->system && $role->slug !== $d['slug'], 422, 'Initial role keys cannot be changed.');
            abort_if($d['slug'] === 'owner', 422, 'The Owner key is reserved.');
            $role->fill(['display_name' => $d['name'], 'name' => $d['slug'], 'slug' => $d['slug'], 'guard_name' => 'api'])->save();
            $role->syncPermissions(Permission::whereIn('id', $d['permission_ids'])->get());
            // Compatibility labels only; permission checks use role_id relations.
            DB::table('users')->where('role_id', $role->id)->update(['role' => $role->slug]);
            DB::table('team_user')->where('team_role_id', $role->id)->update(['team_role' => $role->slug]);
            Workflow::audit($r->user()->id, 'role.saved', (string) $role->id);

            return $role->load('permissions');
        });
    }

    public function destroy(Request $r, int $id)
    {
        $this->owner($r);
        DB::transaction(function () use ($r, $id) {
            $role = Role::lockForUpdate()->findOrFail($id);
            abort_if($role->system, 422, 'Initial roles cannot be deleted.');
            abort_if(DB::table('users')->where('role_id', $id)->exists() || DB::table('team_user')->where('team_role_id', $id)->exists(), 422, 'Reassign users and team memberships before deleting this role.');
            $role->delete();
            Workflow::audit($r->user()->id, 'role.deleted', (string) $id);
        });

        return ['message' => 'Role deleted'];
    }

    public function savePermission(Request $r, ?int $id = null)
    {
        $this->owner($r);
        $d = $r->validate(['name' => 'required|string|max:150', 'slug' => ['required', 'regex:/^[a-z][a-z0-9_.]*$/', 'max:150', Rule::unique('permissions')->ignore($id)]]);

        return DB::transaction(function () use ($r, $id, $d) {
            $p = $id ? Permission::lockForUpdate()->findOrFail($id) : new Permission;
            abort_if($p->system && $p->slug !== $d['slug'], 422, 'Built-in permission keys cannot change.');
            $p->fill(['display_name' => $d['name'], 'name' => $d['slug'], 'slug' => $d['slug'], 'guard_name' => 'api'])->save();
            Workflow::audit($r->user()->id, 'permission.saved', (string) $p->id);

            return $p;
        });
    }

    public function deletePermission(Request $r, int $id)
    {
        $this->owner($r);
        DB::transaction(function () use ($r, $id) {
            $p = Permission::lockForUpdate()->findOrFail($id);
            abort_if($p->system, 422, 'Built-in feature permissions cannot be deleted.');
            $p->delete();
            Workflow::audit($r->user()->id, 'permission.deleted', (string) $id);
        });

        return ['message' => 'Permission deleted and grants removed'];
    }

    public function assign(Request $r, int $id)
    {
        $this->owner($r);
        $d = $r->validate(['role_id' => 'required|integer|exists:roles,id', 'permission_ids' => 'present|array', 'permission_ids.*' => 'integer|distinct|exists:permissions,id']);

        return DB::transaction(function () use ($r, $id, $d) {
            $role = Role::lockForUpdate()->findOrFail($d['role_id']);
            $u = User::lockForUpdate()->findOrFail($id);
            abort_if($id === $r->user()->id && $role->slug !== 'owner', 422, 'You cannot remove your own Owner role.');
            $u->update(['role_id' => $role->id]);
            $u->syncPermissions(Permission::whereIn('id', $d['permission_ids'])->get());
            Workflow::audit($r->user()->id, 'user.access.updated', (string) $id);

            return ['message' => 'User access saved'];
        });
    }
}
