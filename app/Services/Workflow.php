<?php

namespace App\Services;

use App\Models\User;
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
        $members = DB::table('team_user')->whereIn('team_id', $teams)->get();
        $eligible = User::with(['assignedRole.permissions', 'directPermissions'])->where('active', true)->where('id', '!=', $uid)->whereIn('id', $members->pluck('user_id'))->get()->filter(fn ($u) => $u->hasPermission('applications.review'))->pluck('id')->all();
        $owners = User::whereHas('assignedRole', fn ($q) => $q->where('slug', 'owner'))->where('active', true)->where('id', '!=', $uid)->pluck('id')->all();
        $ids = [];
        foreach ($teams as $team) {
            $reviewers = $members->where('team_id', $team)->whereIn('user_id', $eligible)->pluck('user_id')->all();
            $ids = array_merge($ids, $reviewers ?: $owners);
        }
        if (! $teams->count()) {
            $ids = $owners;
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
