<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;
    use HasRoles;
    use Notifiable;

    protected $guard_name = 'api';

    use SoftDeletes;

    protected $appends = ['effective_permissions', 'role_name'];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::saving(function (User $u) {
            if ($u->isDirty('role_id') || $u->isDirty('role')) {
                $u->unsetRelation('assignedRole');
            }
            if ($u->isDirty('role_id')) {
                $u->role = Role::findOrFail($u->role_id)->slug;
            } elseif (! $u->role_id || $u->isDirty('role')) {
                $u->role_id = Role::where('slug', $u->role ?? 'employee')->value('id');
            }
        });
        static::saved(function (User $u) {
            if ($u->role_id) {
                $u->syncRoles(Role::findOrFail($u->role_id));
            }
        });
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_user')->withPivot(['team_role_id', 'team_role']);
    }

    public function assignedRole()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function directPermissions()
    {
        return $this->permissions();
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner', 'api');
    }

    public function getRoleNameAttribute(): ?string
    {
        return $this->assignedRole?->display_name;
    }

    public function getEffectivePermissionsAttribute(): array
    {
        return $this->isOwner() ? Permission::pluck('name')->all() : $this->getAllPermissions()->pluck('name')->values()->all();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        return $this->checkPermissionTo($permission, 'api');
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean'];
    }
}
