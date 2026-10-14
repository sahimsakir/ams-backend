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
    private function grantable(Request $r, array $ids): void
    {
        if ($r->user()->isOwner()) {
            return;
        }
        foreach (Permission::whereIn('id', $ids)->where('deprecated', false)->pluck('name') as $key) {
            abort_unless($r->user()->hasPermission($key), 403, 'You cannot grant permissions you do not hold.');
        }
    }

    public function index(Request $r)
    {
        abort_unless(collect(['roles.view', 'roles.create', 'roles.update', 'teams.members_update', 'users.create', 'users.update', 'users.assign_access'])->contains(fn ($p) => $r->user()->hasPermission($p)), 403);

        return Role::with('permissions')->orderBy('id')->get();
    }

    public function permissions(Request $r)
    {
        abort_unless($r->user()->hasPermission('permissions.view') || $r->user()->hasPermission('roles.create') || $r->user()->hasPermission('roles.update') || $r->user()->hasPermission('users.assign_access'), 403);

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
        abort_unless($r->user()->hasPermission($id ? 'roles.update' : 'roles.create'), 403);
        $d = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('roles', 'display_name')->ignore($id)], 'slug' => ['required', 'regex:/^[a-z][a-z0-9_]*$/', 'max:100', Rule::unique('roles', 'slug')->ignore($id)], 'permission_ids' => 'present|array', 'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where(fn ($q) => $q->where('deprecated', false))]]);

        $this->grantable($r, $d['permission_ids']);

        return DB::transaction(function () use ($r, $id, $d) {
            $role = $id ? Role::lockForUpdate()->findOrFail($id) : new Role;
            if ($role->exists) {
                $this->grantable($r, $role->permissions->pluck('id')->all());
            }
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
        abort_unless($r->user()->hasPermission('roles.delete'), 403);
        DB::transaction(function () use ($r, $id) {
            $role = Role::lockForUpdate()->findOrFail($id);
            $this->grantable($r, $role->permissions->pluck('id')->all());
            abort_if($role->system, 422, 'Initial roles cannot be deleted.');
            abort_if(DB::table('users')->where('role_id', $id)->exists() || DB::table('team_user')->where('team_role_id', $id)->exists(), 422, 'Reassign users and team memberships before deleting this role.');
            $role->delete();
            Workflow::audit($r->user()->id, 'role.deleted', (string) $id);
        });

        return ['message' => 'Role deleted'];
    }

    public function savePermission(Request $r, ?int $id = null)
    {
        abort_unless($r->user()->hasPermission($id ? 'permissions.update' : 'permissions.create'), 403);
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
        abort_unless($r->user()->hasPermission('permissions.delete'), 403);
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
        abort_unless($r->user()->hasPermission('users.assign_access'), 403);
        $d = $r->validate(['role_id' => 'required|integer|exists:roles,id', 'permission_ids' => 'present|array', 'permission_ids.*' => ['integer', 'distinct', Rule::exists('permissions', 'id')->where(fn ($q) => $q->where('deprecated', false))]]);

        return DB::transaction(function () use ($r, $id, $d) {
            $role = Role::lockForUpdate()->findOrFail($d['role_id']);
            $u = User::lockForUpdate()->findOrFail($id);
            abort_if(! $r->user()->isOwner() && ($role->slug === 'owner' || $u->isOwner()), 403);
            $this->grantable($r, $role->permissions->pluck('id')->all());
            $this->grantable($r, $d['permission_ids']);
            abort_if($id === $r->user()->id && $role->slug !== 'owner', 422, 'You cannot remove your own Owner role.');
            $u->update(['role_id' => $role->id]);
            $u->syncPermissions(Permission::whereIn('id', $d['permission_ids'])->get());
            Workflow::audit($r->user()->id, 'user.access.updated', (string) $id);

            return ['message' => 'User access saved'];
        });
    }
}
