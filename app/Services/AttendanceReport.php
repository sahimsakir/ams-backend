<?php

namespace App\Services;

use Carbon\CarbonImmutable as C;
use Illuminate\Support\Facades\DB;

class AttendanceReport
{
    public static function slot(int $uid): array
    {
        $u = DB::table('users')->where('id', $uid)->first();
        if ($u->slot_start && $u->slot_end) {
            return [$u->slot_start, $u->slot_end];
        }
        $teams = DB::table('teams')->join('team_user', 'team_user.team_id', '=', 'teams.id')->where('user_id', $uid)->whereNotNull('slot_start')->whereNotNull('slot_end');
        $t = $u->slot_team_id ? (clone $teams)->where('teams.id', $u->slot_team_id)->first() : null;
        $t = $t ?? $teams->orderBy('teams.id')->first();

        return $t ? [$t->slot_start, $t->slot_end] : ['09:00:00', '18:00:00'];
    }

    public static function build(array $uids, string $start, string $end): array
    {
        $records = DB::table('attendances')->whereIn('user_id', $uids)->whereBetween('work_date', [$start, $end])->get()->keyBy(fn ($a) => $a->user_id.'|'.$a->work_date);
        $leaves = DB::table('applications')->leftJoin('leave_types', 'leave_types.id', '=', 'applications.leave_type_id')->whereIn('user_id', $uids)->where('kind', 'leave')->where('status', 'approved')->where('start_date', '<=', $end)->where('end_date', '>=', $start)->select('applications.*', 'leave_types.name as leave_name')->get()->groupBy('user_id');
        $calendar = DB::table('calendar_days')->whereBetween('date', [$start, $end])->pluck('is_working', 'date');
        $users = DB::table('users')->whereIn('id', $uids)->orderBy('name')->get();
        $teamSlots = DB::table('team_user')->join('teams', 'teams.id', '=', 'team_user.team_id')->whereIn('user_id', $uids)->whereNotNull('slot_start')->whereNotNull('slot_end')->orderBy('teams.id')->select('team_user.user_id', 'teams.id', 'slot_start', 'slot_end')->get()->groupBy('user_id');
        $today = C::now('Asia/Dhaka')->toDateString();
        $rows = [];
        $s = ['working_days' => 0, 'late_days' => 0, 'leave_days' => 0, 'home_office_days' => 0, 'missing_exits' => 0, 'completed_days' => 0, 'early_arrival_days' => 0, 'late_departure_days' => 0, 'attendance_days' => 0];
        $total = 0;
        $actual = 0;
        $early = 0;
        $departure = 0;
        foreach ($users as $u) {
            $configured = $teamSlots->get($u->id, collect());
            $team = $configured->firstWhere('id', $u->slot_team_id) ?? $configured->first();
            $inheritedSlot = $u->slot_start && $u->slot_end ? [$u->slot_start, $u->slot_end] : ($team ? [$team->slot_start, $team->slot_end] : ['09:00:00', '18:00:00']);
            for ($date = C::parse($start); $date->toDateString() <= $end; $date = $date->addDay()) {
                $key = $date->toDateString();
                $working = $calendar->has($key) ? (bool) $calendar[$key] : ! in_array($date->dayOfWeek, [5, 6]);
                $a = $records->get($u->id.'|'.$key);
                $leave = ($leaves->get($u->id, collect()))->first(fn ($l) => $l->start_date <= $key && $l->end_date >= $key);
                if ($working && $key <= $today) {
                    $s['working_days']++;
                }
                if (! $a && ! $leave) {
                    continue;
                }
                if (! $a && ! $working) {
                    continue;
                }
                [$from,$to] = $a ? [$a->slot_start, $a->slot_end] : $inheritedSlot;
                $slotStart = C::parse($key.' '.$from, 'Asia/Dhaka');
                $slotEnd = C::parse($key.' '.$to, 'Asia/Dhaka');
                $in = $a ? C::parse($a->check_in, 'UTC')->setTimezone('Asia/Dhaka') : null;
                $out = $a && $a->check_out ? C::parse($a->check_out, 'UTC')->setTimezone('Asia/Dhaka') : null;
                $missing = $a && ! $out && $key < $today;
                $status = $a ? ($missing ? 'missing_exit' : ($out ? 'complete' : 'checked_in')) : 'leave';
                $elapsed = null;
                $inside = null;
                if ($a) {
                    $s['attendance_days']++;
                    if ($in > $slotStart) {
                        $s['late_days']++;
                    }
                    if ($a->mode === 'home_office') {
                        $s['home_office_days']++;
                    }
                    if ($missing) {
                        $s['missing_exits']++;
                    }
                    $earlyMinutes = max(0, ($slotStart->getTimestamp() - $in->getTimestamp()) / 60);
                    $early += $earlyMinutes;
                    if ($earlyMinutes > 0) {
                        $s['early_arrival_days']++;
                    }
                    if ($out) {
                        $elapsed = max(0, ($out->getTimestamp() - $in->getTimestamp()) / 60);
                        $inside = max(0, (min($out->getTimestamp(), $slotEnd->getTimestamp()) - max($in->getTimestamp(), $slotStart->getTimestamp())) / 60);
                        $total += $elapsed;
                        $actual += $inside;
                        $s['completed_days']++;
                        $late = max(0, ($out->getTimestamp() - $slotEnd->getTimestamp()) / 60);
                        $departure += $late;
                        if ($late > 0) {
                            $s['late_departure_days']++;
                        }
                    }
                } elseif ($key <= $today) {
                    $s['leave_days']++;
                }
                $rows[] = ['id' => $a ? $a->id : 'leave-'.$u->id.'-'.$key, 'user_id' => $u->id, 'name' => $u->name, 'work_date' => $key, 'check_in' => $in?->utc()->toISOString(), 'check_out' => $out?->utc()->toISOString(), 'mode' => $a ? $a->mode : 'leave', 'leave_name' => $leave?->leave_name, 'status' => $status, 'slot_start' => substr($from, 0, 5), 'slot_end' => substr($to, 0, 5), 'working_minutes' => $elapsed === null ? null : round($elapsed), 'actual_minutes' => $inside === null ? null : round($inside)];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($b['work_date'], $a['work_date']) ?: strcmp($a['name'], $b['name']));
        $s['avg_working_minutes'] = $s['completed_days'] ? round($total / $s['completed_days']) : 0;
        $s['actual_avg_minutes'] = $s['completed_days'] ? round($actual / $s['completed_days']) : 0;
        $s['early_arrival_avg_minutes'] = $s['attendance_days'] ? round($early / $s['attendance_days']) : 0;
        $s['late_departure_avg_minutes'] = $s['completed_days'] ? round($departure / $s['completed_days']) : 0;
        foreach (['late', 'leave', 'home_office'] as $k) {
            $s[$k.'_percent'] = $s['working_days'] ? round($s[$k.'_days'] / $s['working_days'] * 100, 2) : 0;
        }

        return ['data' => $rows, 'summary' => $s, 'start_date' => $start, 'end_date' => $end];
    }
}
