<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Workflow
{
    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['request' => $message]);
    }

    public static function days(string $start, string $end): int
    {
        $overrides = DB::table('calendar_days')->whereBetween('date', [$start, $end])->pluck('is_working', 'date');
        $days = 0;
        for ($d = CarbonImmutable::parse($start); $d->lte(CarbonImmutable::parse($end)); $d = $d->addDay()) {
            $working = $overrides->has($d->toDateString()) ? (bool) $overrides[$d->toDateString()] : ! in_array($d->dayOfWeek, [5, 6]);
            if ($working) {
                $days++;
            }
        }

return $days;
    }

    public static function reviewers(int $uid): array
    {
        $teams = DB::table('team_user')->where('user_id', $uid)->pluck('team_id');
        $ids = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')->whereIn('team_id', $teams)->whereIn('team_role', ['team_lead', 'manager', 'pm'])->where('users.active', true)->where('user_id', '!=', $uid)->pluck('user_id')->unique()->values()->all();
        // Owner fallback only when a team has no eligible reviewer.
        foreach ($teams as $team) {
            $has = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')->where('team_id', $team)->whereIn('team_role', ['team_lead', 'manager', 'pm'])->where('users.active', true)->where('user_id', '!=', $uid)->exists();
            if (! $has) {
                $ids = array_merge($ids, DB::table('users')->where('role', 'owner')->where('active', true)->where('id', '!=', $uid)->pluck('id')->all());
            }
        }
        if (! $teams->count()) {
            $ids = DB::table('users')->where('role', 'owner')->where('active', true)->where('id', '!=', $uid)->pluck('id')->all();
        }
        if (! $ids) {
            self::fail('Assign an eligible reviewer before submitting. Self-approval is not allowed.');
        }

return array_values(array_unique($ids));
    }

    public static function audit(int $actor, string $action, string $subject): void
    {
        DB::table('audit_logs')->insert(['actor_id' => $actor, 'action' => $action, 'subject' => $subject, 'created_at' => now()]);
    }
}
