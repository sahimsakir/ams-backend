<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['system' => 'boolean'];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_permission');
    }
}
