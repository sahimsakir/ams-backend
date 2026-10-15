<?php

namespace App\Services;

use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\DB;

class WorkforceAnalytics
{
    public static function build(array $uids, string $date, string $month, bool $includeDaily = true, bool $includeMonthly = true): array
    {
        $today = C::now('Asia/Dhaka')->toDateString();
        $monthStart = $month.'-01';
        $monthEnd = min(C::parse($monthStart)->endOfMonth()->toDateString(), $today);
        $start = $includeDaily ? ($includeMonthly ? min($date, $monthStart) : $date) : $monthStart;
        $end = $includeDaily ? ($includeMonthly ? max($date, $monthEnd) : $date) : $monthEnd;
        $users = DB::table('users')->whereIn('id', $uids)->whereNull('deleted_at')->where('active', true)->get(['id', 'created_at']);
        $records = DB::table('attendances')->whereIn('user_id', $users->pluck('id'))->whereBetween('work_date', [$start, $end])->get()->keyBy(fn ($a) => $a->user_id.'|'.$a->work_date);
        $applications = DB::table('applications')->whereIn('user_id', $users->pluck('id'))->where('status', 'approved')->whereIn('kind', ['leave', 'home_office'])->where('start_date', '<=', $end)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $start))->get()->groupBy('user_id');
        $memberships = DB::table('team_user')->whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id');
        $assignments = DB::table('team_home_office')->whereNull('cancelled_at')->where('start_date', '<=', $end)->where('end_date', '>=', $start)->get();
        $calendar = DB::table('calendar_days')->whereBetween('date', [$start, $end])->pluck('is_working', 'date');
        $joined = fn ($u) => $u->created_at ? C::parse($u->created_at, 'UTC')->setTimezone('Asia/Dhaka')->toDateString() : '0000-00-00';
        $day = function (string $key) use ($users, $records, $applications, $memberships, $assignments, $calendar, $joined, $today) {
            $working = $calendar->has($key) ? (bool) $calendar[$key] : ! in_array(C::parse($key)->dayOfWeek, [5, 6]);
            $s = ['date' => $key, 'working_day' => $working, 'provisional' => $key === $today, 'total_employees' => 0, 'new_joiners' => 0, 'present' => 0, 'absent' => 0, 'on_leave' => 0, 'home_office' => 0, 'missing_exits' => 0, 'working_minutes' => 0];
            foreach ($users as $u) {
                if ($joined($u) > $key) {
                    continue;
                }
                $s['total_employees']++;
                if ($joined($u) === $key) {
                    $s['new_joiners']++;
                }
                $a = $records->get($u->id.'|'.$key);
                $apps = $applications->get($u->id, collect())->filter(fn ($app) => $app->start_date <= $key && (! $app->end_date || $app->end_date >= $key));
                $leave = $working && $apps->contains('kind', 'leave');
                $teams = $memberships->get($u->id, collect())->pluck('team_id');
                $home = $apps->contains('kind', 'home_office') || ($teams->isNotEmpty() && $assignments->contains(fn ($range) => $range->start_date <= $key && $range->end_date >= $key && (! $range->team_id || $teams->contains($range->team_id))));
                if ($a) {
                    $s['present']++;
                    if ($a->mode === 'home_office') {
                        $s['home_office']++;
                    }
                    if (! $a->check_out && $key < $today) {
                        $s['missing_exits']++;
                    }
                    if ($a->check_out) {
                        $s['working_minutes'] += max(0, (C::parse($a->check_out)->getTimestamp() - C::parse($a->check_in)->getTimestamp()) / 60);
                    }
                } elseif ($leave) {
                    $s['on_leave']++;
                } else {
                    if ($working) {
                        $s['absent']++;
                    }
                    if ($working && $home) {
                        $s['home_office']++;
                    }
                }
            }
            $s['working_minutes'] = (int) round($s['working_minutes']);

            return $s;
        };
        $daily = $includeDaily ? $day($date) : null;
        $trend = [];
        for ($d = C::parse($monthStart); $includeMonthly && $d->toDateString() <= $monthEnd; $d = $d->addDay()) {
            $trend[] = $day($d->toDateString());
        }
        $monthly = ['month' => $month, 'through' => $monthEnd, 'total_employees' => $trend ? end($trend)['total_employees'] : 0, 'new_joiners' => collect($trend)->sum('new_joiners'), 'present_days' => collect($trend)->sum('present'), 'absent_days' => collect($trend)->where('provisional', false)->sum('absent'), 'leave_days' => collect($trend)->sum('on_leave'), 'home_office_days' => collect($trend)->sum('home_office'), 'missing_exits' => collect($trend)->sum('missing_exits'), 'working_minutes' => collect($trend)->sum('working_minutes'), 'trend' => $trend];

        return compact('daily', 'monthly');
    }
}
