<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\ApplicationNotice;
use App\Services\Workflow as W;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AmsController extends Controller
{
    private function owner(Request $r): void
    {
        abort_unless($r->user()->role === 'owner', 403);
    }

    private function visible(Request $r): array
    {
        if ($r->user()->role === 'owner') {
            return DB::table('users')->pluck('id')->all();
        }
        $teams = DB::table('team_user')->where('user_id', $r->user()->id)->whereIn('team_role', ['team_lead', 'manager', 'pm'])->pluck('team_id');

        return DB::table('team_user')->whereIn('team_id', $teams)->pluck('user_id')->push($r->user()->id)->unique()->all();
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $u = User::where('email', $d['email'])->first();
        abort_unless($u && $u->active && Hash::check($d['password'], $u->password), 401, 'Invalid credentials.');
        $t = bin2hex(random_bytes(32));
        DB::table('access_tokens')->insert(['user_id' => $u->id, 'token_hash' => hash('sha256', $t), 'expires_at' => now()->addHours(8)]);

        return ['token' => $t, 'user' => $u];
    }

    public function logout(Request $r)
    {
        DB::table('access_tokens')->where('token_hash', hash('sha256', $r->bearerToken()))->delete();

        return ['message' => 'Signed out'];
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required', 'password' => 'required|string|min:12|confirmed']);
        abort_unless(Hash::check($d['current_password'], $r->user()->password), 422, 'Current password incorrect');
        $r->user()->update(['password' => $d['password']]);
        DB::table('access_tokens')->where('user_id', $r->user()->id)->delete();

        return ['message' => 'Password changed. Sign in again.'];
    }

    public function me(Request $r)
    {
        return ['user' => $r->user(), 'face_enrolled' => DB::table('face_profiles')->where('user_id', $r->user()->id)->exists()];
    }

    public function leaveTypes()
    {
        return DB::table('leave_types')->orderBy('name')->get();
    }

    public function dashboard(Request $r)
    {
        $uid = $r->user()->id;
        $year = now('Asia/Dhaka')->year;
        $balances = DB::table('leave_types')->get()->map(function ($t) use ($uid, $year) {
            $base = DB::table('applications')->where('user_id', $uid)->where('leave_type_id', $t->id)->whereYear('start_date', $year);
            $t->used = (clone $base)->where('status', 'approved')->sum('days');
            $t->reserved = (clone $base)->where('status', 'pending')->sum('days');
            $t->remaining = $t->annual_limit - $t->used - $t->reserved;

            return $t;
        });

        return ['balances' => $balances, 'today' => DB::table('attendances')->where('user_id', $uid)->where('work_date', now('Asia/Dhaka')->toDateString())->first(), 'pending_reviews' => DB::table('approvals')->join('applications', 'applications.id', '=', 'approvals.application_id')->where('reviewer_id', $uid)->where('approvals.status', 'pending')->where('applications.status', 'pending')->count(), 'teams' => DB::table('team_user')->join('teams', 'teams.id', '=', 'team_user.team_id')->where('user_id', $uid)->select('teams.name', 'team_role')->get()];
    }

    public function applications(Request $r)
    {
        $uid = $r->user()->id;
        $q = DB::table('applications')->join('users', 'users.id', '=', 'applications.user_id')->leftJoin('leave_types', 'leave_types.id', '=', 'applications.leave_type_id')->select('applications.*', 'users.name as applicant', 'leave_types.name as leave_name');
        if ($r->user()->role !== 'owner') {
            $q->where(fn ($q) => $q->where('applications.user_id', $uid)->orWhereIn('applications.id', DB::table('approvals')->where('reviewer_id', $uid)->select('application_id')));
        }$items = $q->orderByDesc('applications.id')->paginate(50);
        $ids = collect($items->items())->pluck('id');
        $approvals = DB::table('approvals')->join('users', 'users.id', '=', 'approvals.reviewer_id')->whereIn('application_id', $ids)->select('approvals.*', 'users.name')->get()->groupBy('application_id');
        foreach ($items as $item) {
            $item->approvals = $approvals->get($item->id, collect());
        }

return $items;
    }

    public function apply(Request $r)
    {
        $d = $r->validate(['kind' => 'required|in:leave,home_office,overtime', 'leave_type_id' => 'nullable|exists:leave_types,id', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date', 'reason' => 'required|string|min:5|max:4000', 'contact' => 'nullable|string|max:100', 'covering' => 'nullable|string|max:100', 'minutes' => 'nullable|integer|min:1|max:1440']);
        $uid = $r->user()->id;
        $end = $d['end_date'] ?? null;
        if ($d['kind'] !== 'home_office' && ! $end) {
            W::fail('An end date is required.');
        }
        if ($end && CarbonImmutable::parse($d['start_date'])->diffInDays(CarbonImmutable::parse($end)) > 366) {
            W::fail('Use a range of at most one year.');
        }
        if ($d['kind'] === 'leave' && (! $r->leave_type_id || substr($d['start_date'], 0, 4) !== substr($end, 0, 4))) {
            W::fail('Select a leave type and submit each calendar year separately.');
        }
        if ($d['kind'] === 'overtime' && (! $r->minutes || $d['start_date'] !== $end)) {
            W::fail('Submit overtime for one date with total minutes.');
        }
        $id = DB::transaction(function () use ($d, $uid, $end) {
            DB::table('users')->where('id', $uid)->lockForUpdate()->first();
            $conflicts = DB::table('applications')->where('user_id', $uid)->whereIn('status', ['pending', 'approved'])->where('start_date', '<=', $end ?? '9999-12-31')->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $d['start_date']));
            if ($d['kind'] === 'overtime') {
                $conflicts->whereIn('kind', ['leave', 'overtime']);
            } elseif ($d['kind'] === 'leave') {
                $conflicts->whereIn('kind', ['leave', 'overtime']);
            } else {
                $conflicts->where('kind', 'home_office');
            }
            if ($conflicts->exists()) {
                W::fail('This overlaps an existing pending or approved request.');
            }
            $days = $end ? W::days($d['start_date'], $end) : 0;
            if ($d['kind'] === 'leave') {
                $t = DB::table('leave_types')->where('id', $d['leave_type_id'])->first();
                $used = DB::table('applications')->where('user_id', $uid)->where('leave_type_id', $t->id)->whereIn('status', ['pending', 'approved'])->whereYear('start_date', substr($d['start_date'], 0, 4))->sum('days');
                if (! $days || $days + $used > $t->annual_limit) {
                    W::fail('Insufficient leave balance or no working days selected.');
                }
            }
            $reviewers = W::reviewers($uid);
            $id = DB::table('applications')->insertGetId([...$d, 'leave_type_id' => $d['kind'] === 'leave' ? $d['leave_type_id'] : null, 'minutes' => $d['kind'] === 'overtime' ? $d['minutes'] : null, 'end_date' => $end, 'user_id' => $uid, 'days' => $days, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($reviewers as $rid) {
                DB::table('approvals')->insert(['application_id' => $id, 'reviewer_id' => $rid, 'status' => 'pending']);
                User::find($rid)->notify(new ApplicationNotice($id, 'A request is waiting for your review.'));
            }W::audit($uid, 'application.submitted', (string) $id);

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Submitted for approval'], 201);
    }

    public function decision(Request $r, int $id)
    {
        $d = $r->validate(['decision' => 'required|in:approved,rejected', 'comment' => 'required_if:decision,rejected|nullable|string|max:2000']);
        DB::transaction(function () use ($r, $id, $d) {
            $a = DB::table('applications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($a, 404);
            abort_if($a->user_id === $r->user()->id, 403);
            abort_unless($a->status === 'pending', 409, 'Already resolved');
            $review = DB::table('approvals')->where('application_id', $id)->where('reviewer_id', $r->user()->id)->first();
            abort_unless($review && $review->status === 'pending', 403);
            DB::table('approvals')->where('id', $review->id)->update(['status' => $d['decision'], 'comment' => $d['comment'] ?? null, 'decided_at' => now()]);
            $status = $d['decision'] === 'rejected' ? 'rejected' : (DB::table('approvals')->where('application_id', $id)->where('status', '!=', 'approved')->exists() ? 'pending' : 'approved');
            DB::table('applications')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            W::audit($r->user()->id, 'application.'.$d['decision'], (string) $id);
            User::find($a->user_id)->notify(new ApplicationNotice($id, 'Your request status: '.$status));
        });

        return ['message' => 'Decision recorded'];
    }

    public function cancel(Request $r, int $id)
    {
        DB::transaction(function () use ($r, $id) {
            $a = DB::table('applications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($a, 404);
            abort_unless($a->user_id === $r->user()->id || $r->user()->role === 'owner', 403);
            abort_unless($a->status === 'pending' || ($a->kind === 'home_office' && $a->status === 'approved'), 409, 'Only pending requests or active home-office arrangements can be cancelled');
            DB::table('applications')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            W::audit($r->user()->id, 'application.cancelled', (string) $id);
            foreach (DB::table('approvals')->where('application_id', $id)->pluck('reviewer_id')->push($a->user_id)->unique() as $uid) {
                User::find($uid)->notify(new ApplicationNotice($id, 'This request was cancelled or its home-office arrangement revoked.'));
            }
        });

        return ['message' => 'Cancelled'];
    }

    private function encoding(Request $r): array
    {
        $r->validate(['photo' => 'required|file|mimes:jpeg,png|max:4096', 'consent' => 'accepted']);
        try {
            $response = Http::timeout(30)->withHeaders(['X-Service-Key' => config('ams.face_key')])->attach('photo', file_get_contents($r->file('photo')->path()), 'capture.jpg')->post(config('ams.face_url').'/encode');
        } catch (\Throwable $e) {
            abort(503, 'Face service unavailable. Try again later.');
        }abort_unless($response->successful(), 422, 'Face capture failed. Use one clearly visible face in good lighting.');
        $v = $response->json('embedding');
        abort_unless(is_array($v) && count($v) === 128, 503, 'Invalid face service response');

        return $v;
    }

    public function enroll(Request $r, int $id)
    {
        $this->owner($r);
        User::findOrFail($id);
        $v = $this->encoding($r);
        DB::table('face_profiles')->updateOrInsert(['user_id' => $id], ['embedding' => Crypt::encryptString(json_encode($v)), 'enrolled_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        W::audit($r->user()->id, 'face.enrolled', (string) $id);

        return ['message' => 'Face enrolled'];
    }

    public function deleteFace(Request $r, int $id)
    {
        $this->owner($r);
        DB::table('face_profiles')->where('user_id', $id)->delete();
        W::audit($r->user()->id, 'face.deleted', (string) $id);

        return ['message' => 'Face template deleted'];
    }

    public function punch(Request $r)
    {
        $d = $r->validate(['action' => 'required|in:in,out']);
        $uid = $r->user()->id;
        $f = DB::table('face_profiles')->where('user_id', $uid)->first();
        abort_unless($f, 422, 'Ask the owner to enroll your face first.');
        $v = $this->encoding($r);
        $known = json_decode(Crypt::decryptString($f->embedding), true);
        $distance = sqrt(array_sum(array_map(fn ($a, $b) => ($a - $b) ** 2, $known, $v)));
        abort_if($distance > config('ams.threshold'), 422, 'Face does not match your enrolled profile.');

        return DB::transaction(function () use ($r, $uid, $d, $distance) {
            DB::table('users')->where('id', $uid)->lockForUpdate()->first();
            $today = now('Asia/Dhaka')->toDateString();
            $open = DB::table('attendances')->where('user_id', $uid)->whereNull('check_out')->first();
            $existing = DB::table('attendances')->where('user_id', $uid)->where('work_date', $today)->first();
            if ($d['action'] === 'in') {
                abort_if($open || $existing, 409, 'Already checked in today or a previous shift is still open.');
                $leave = DB::table('applications')->where('user_id', $uid)->where('kind', 'leave')->where('status', 'approved')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->exists();
                abort_if($leave, 422, 'You are on approved leave.');
                $home = DB::table('applications')->where('user_id', $uid)->where('kind', 'home_office')->where('status', 'approved')->where('start_date', '<=', $today)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))->exists();
                $path = $r->file('photo')->store('attendance', 'local');
                try {
                    DB::table('attendances')->insert(['user_id' => $uid, 'work_date' => $today, 'check_in' => now(), 'mode' => $home ? 'home_office' : 'office', 'in_photo' => $path, 'in_distance' => $distance]);
                } catch (\Throwable $e) {
                    Storage::disk('local')->delete($path);
                    throw $e;
                }
            } else {
                abort_unless($open, 409, 'No open shift to check out.');
                $path = $r->file('photo')->store('attendance', 'local');
                try {
                    DB::table('attendances')->where('id', $open->id)->update(['check_out' => now(), 'out_photo' => $path, 'out_distance' => $distance]);
                } catch (\Throwable $e) {
                    Storage::disk('local')->delete($path);
                    throw $e;
                }
            }
            W::audit($uid, 'attendance.'.$d['action'], $today);

            return ['message' => $d['action'] === 'in' ? 'Checked in successfully' : 'Checked out successfully'];
        });
    }

    public function attendance(Request $r)
    {
        return DB::table('attendances')->join('users', 'users.id', '=', 'attendances.user_id')->whereIn('user_id', $this->visible($r))->select('attendances.id', 'user_id', 'users.name', 'work_date', 'check_in', 'check_out', 'mode')->orderByDesc('attendances.id')->paginate(100);
    }

    public function photo(Request $r, int $id, string $side)
    {
        abort_unless(in_array($side, ['in', 'out']), 404);
        $a = DB::table('attendances')->where('id', $id)->first();
        abort_unless($a && in_array($a->user_id, $this->visible($r)), 404);
        $path = $a->{$side.'_photo'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function users(Request $r)
    {
        $this->owner($r);

        return User::orderBy('name')->get()->map(function ($u) {
            $u->face_enrolled = DB::table('face_profiles')->where('user_id', $u->id)->exists();

            return $u;
        });
    }

    public function saveUser(Request $r, ?int $id = null)
    {
        $this->owner($r);
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => ['required', 'email', Rule::unique('users')->ignore($id)], 'role' => 'required|in:owner,manager,pm,team_lead,employee', 'password' => ($id ? 'nullable' : 'required').'|string|min:12', 'active' => 'required|boolean', 'designation' => 'nullable|string|max:150', 'phone' => 'nullable|string|max:50']);
        if ($id === $r->user()->id && (! $d['active'] || $d['role'] !== 'owner')) {
            W::fail('You cannot deactivate or demote your own owner account.');
        }if (empty($d['password'])) {
            unset($d['password']);
        }$u = $id ? User::findOrFail($id) : new User;
        $u->fill($d)->save();
        W::audit($r->user()->id, 'user.saved', (string) $u->id);

        return $u;
    }

    public function teams(Request $r)
    {
        $q = DB::table('teams');
        if ($r->user()->role !== 'owner') {
            $q->whereIn('id', DB::table('team_user')->where('user_id', $r->user()->id)->select('team_id'));
        }

return $q->get()->map(function ($t) {
            $t->members = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')->where('team_id', $t->id)->select('users.id', 'users.name', 'team_role')->get();

            return $t;
        });
    }

    public function saveTeam(Request $r)
    {
        $this->owner($r);
        $d = $r->validate(['name' => 'required|string|max:100|unique:teams']);
        $id = DB::table('teams')->insertGetId([...$d, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id];
    }

    public function members(Request $r, int $id)
    {
        $this->owner($r);
        abort_unless(DB::table('teams')->where('id', $id)->exists(), 404);
        $d = $r->validate(['members' => 'present|array', 'members.*.user_id' => 'required|distinct|exists:users,id', 'members.*.team_role' => 'required|in:employee,team_lead,manager,pm']);
        DB::transaction(function () use ($d, $id, $r) {
            DB::table('team_user')->where('team_id', $id)->delete();
            foreach ($d['members'] as $m) {
                DB::table('team_user')->insert([...$m, 'team_id' => $id]);
            }W::audit($r->user()->id, 'team.members.updated', (string) $id);
        });

        return ['message' => 'Team assignments saved'];
    }

    public function saveLeaveType(Request $r)
    {
        $this->owner($r);
        $d = $r->validate(['name' => 'required|string|max:100|unique:leave_types', 'annual_limit' => 'required|integer|min:0|max:366']);
        DB::table('leave_types')->insert([...$d, 'created_at' => now(), 'updated_at' => now()]);

        return ['message' => 'Leave type created'];
    }

    public function calendar()
    {
        return DB::table('calendar_days')->orderBy('date')->get();
    }

    public function saveCalendar(Request $r)
    {
        $this->owner($r);
        $d = $r->validate(['date' => 'required|date_format:Y-m-d', 'name' => 'required|string|max:150', 'is_working' => 'required|boolean']);
        DB::table('calendar_days')->updateOrInsert(['date' => $d['date']],$d);

        return ['message' => 'Calendar updated'];
    }

    public function deleteCalendar(Request $r,int $id)
    {
        $this->owner($r);
        DB::table('calendar_days')->where('id',$id)->delete();

        return ['message' => 'Calendar override removed'];
    }

    public function audit(Request $r)
    {
        $this->owner($r);

        return DB::table('audit_logs')->join('users','users.id','=','audit_logs.actor_id')->select('audit_logs.*','users.name')->orderByDesc('audit_logs.id')->paginate(100);
    }
}
