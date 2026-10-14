<?php

namespace App\Models;

class Permission extends \Spatie\Permission\Models\Permission
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function ($model) {
            $model->guard_name = 'api';
            if ($model->slug) {
                $model->display_name = $model->display_name ?? $model->name;
                $model->name = $model->slug;
            }
        });
    }

    protected function casts(): array
    {
        return ['system' => 'boolean'];
    }
}
