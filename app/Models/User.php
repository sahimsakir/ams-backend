<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
    use SoftDeletes;

    protected $appends = ['permissions', 'role_name'];

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
        return $this->belongsToMany(Permission::class, 'user_permission');
    }

    public function isOwner(): bool
    {
        return $this->assignedRole?->slug === 'owner';
    }

    public function getRoleNameAttribute(): ?string
    {
        return $this->assignedRole?->name;
    }

    public function getPermissionsAttribute(): array
    {
        if ($this->isOwner()) {
            return Permission::pluck('slug')->all();
        }

        return collect($this->assignedRole?->permissions->pluck('slug')->all() ?? [])->merge($this->directPermissions->pluck('slug'))->unique()->values()->all();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isOwner() || in_array($permission, $this->permissions, true);
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean'];
    }
}
