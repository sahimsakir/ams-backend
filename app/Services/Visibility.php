<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class Visibility
{
    public static function teamUsers(User $user): array
    {
        return DB::table('team_user')->whereIn('team_id', DB::table('team_user')->where('user_id', $user->id)->select('team_id'))->pluck('user_id')->push($user->id)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function users(User $user, string $feature): array
    {
        if ($user->hasPermission($feature.'.view_all')) {
            return DB::table('users')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        if ($user->hasPermission($feature.'.view_team')) {
            return DB::table('team_user')->whereIn('team_id', DB::table('team_user')->where('user_id', $user->id)->select('team_id'))->pluck('user_id')->push($user->id)->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        return [$user->id];
    }

    public static function application(User $user, object $app): bool
    {
        return in_array((int) $app->user_id, self::users($user, 'applications'), true) || ($user->hasPermission('applications.view') && DB::table('approvals')->where('application_id', $app->id)->where('reviewer_id', $user->id)->exists());
    }
}
