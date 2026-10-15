<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Notifications\ApplicationNotice;
use App\Services\AttendanceReport;
use App\Services\BrowserAuth;
use App\Services\FaceProof;
use App\Services\TableFilters;
use App\Services\Visibility;
use App\Services\Workflow as W;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AmsController extends Controller
{
    private function visible(Request $r): array
    {
        return Visibility::users($r->user(), 'attendance');
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $u = User::where('email', $d['email'])->first();
        abort_unless($u && $u->active && Hash::check($d['password'], $u->password), 401, 'Invalid credentials.');
        $r->session()->regenerate();
        $issued = $u->createToken('AMS browser');

        return response()->json(['user' => $u])->cookie(BrowserAuth::cookie($issued->accessToken));
    }

    public function logout(Request $r)
    {
        $r->user()->token()->revoke();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->json(['message' => 'Signed out'])->cookie(BrowserAuth::forget());
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required', 'password' => 'required|string|min:6|confirmed']);
        abort_unless(Hash::check($d['current_password'], $r->user()->password), 422, 'Current password incorrect');
        $r->user()->update(['password' => $d['password']]);
        BrowserAuth::revokeUser($r->user()->id);

        return response()->json(['message' => 'Password changed. Sign in again.'])->cookie(BrowserAuth::forget());
    }

    public function me(Request $r)
    {
        return ['user' => $r->user(), 'face_enrolled' => DB::table('face_profiles')->where('user_id', $r->user()->id)->where('verification_version', '>=', 2)->exists()];
    }

    public function leaveTypes()
    {
        return DB::table('leave_types')->whereNull('deleted_at')->orderBy('name')->get();
    }

    public function attendancePeople(Request $r)
    {
        return DB::table('users')->whereIn('id', $this->visible($r))->orderBy('name')->get(['id', 'name']);
    }

    public function profile(Request $r, ?int $id = null)
    {
        $id = $id ?? $r->user()->id;
        if ($id === $r->user()->id) {
            abort_unless($r->user()->hasPermission('profile.view_self'), 403);
        } else {
            abort_unless(($r->user()->hasPermission('profile.view_team') || $r->user()->hasPermission('profile.view_all')) && in_array($id, Visibility::users($r->user(), 'profile'), true), 403);
        }
        $u = User::findOrFail($id);

        return ['user' => $u->only(['id', 'name', 'email', 'designation', 'phone', 'role_name', 'active', 'slot_start', 'slot_end'])];
    }

    public function saveProfile(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'phone' => 'nullable|string|max:50']);
        $r->user()->update($d);
        W::audit($r->user()->id, 'profile.updated', (string) $r->user()->id);

        return ['message' => 'Profile updated'];
    }

    public function dashboard(Request $r)
    {
        $uid = $r->user()->id;
        $year = now('Asia/Dhaka')->year;
        $balances = DB::table('leave_types')->whereNull('deleted_at')->get()->map(function ($t) use ($uid, $year) {
            $base = DB::table('applications')->where('user_id', $uid)->where('leave_type_id', $t->id)->whereYear('start_date', $year);
            $t->used = (clone $base)->where('status', 'approved')->sum('days');
            $t->reserved = (clone $base)->where('status', 'pending')->sum('days');
            $t->remaining = $t->annual_limit - $t->used - $t->reserved;

            return $t;
        });

        return ['balances' => $balances, 'today' => FaceProof::attendance(DB::table('attendances')->where('user_id', $uid)->where('work_date', now('Asia/Dhaka')->toDateString())->first()), 'pending_reviews' => DB::table('approvals')->join('applications', 'applications.id', '=', 'approvals.application_id')->where('reviewer_id', $uid)->where('approvals.status', 'pending')->where('applications.status', 'pending')->count(), 'teams' => DB::table('team_user')->join('teams', 'teams.id', '=', 'team_user.team_id')->where('user_id', $uid)->select('teams.name', 'team_role')->get()];
    }

    public function applications(Request $r)
    {
        $uid = $r->user()->id;
        $q = DB::table('applications')->join('users', 'users.id', '=', 'applications.user_id')->leftJoin('leave_types', 'leave_types.id', '=', 'applications.leave_type_id')->select('applications.*', 'users.name as applicant', 'leave_types.name as leave_name');
        if (! $r->user()->hasPermission('applications.view_all')) {
            $visible = Visibility::users($r->user(), 'applications');
            $q->where(fn ($q) => $q->whereIn('applications.user_id', $visible)->orWhereIn('applications.id', DB::table('approvals')->where('reviewer_id', $uid)->select('application_id')));
        }$items = $q->orderByDesc('applications.id')->paginate(50);
        $ids = collect($items->items())->pluck('id');
        $approvals = DB::table('approvals')->join('users', 'users.id', '=', 'approvals.reviewer_id')->whereIn('application_id', $ids)->select('approvals.*', 'users.name')->get()->groupBy('application_id');
        foreach ($items as $item) {
            $item->can_cancel = (int) $item->user_id === $uid ? $r->user()->hasPermission('applications.cancel_self') : ($r->user()->hasPermission('applications.cancel_any') || ($r->user()->hasPermission('applications.cancel_team') && in_array((int) $item->user_id, Visibility::teamUsers($r->user()), true)));
            $item->has_document = (bool) $item->document;
            unset($item->document);
            $item->approvals = $approvals->get($item->id, collect());
        }

        return $items;
    }

    public function apply(Request $r)
    {
        abort_unless($r->user()->hasPermission('applications.create_'.(string) $r->input('kind')), 403);
        $d = $r->validate(['kind' => 'required|in:leave,home_office,overtime', 'leave_type_id' => ['nullable', Rule::exists('leave_types', 'id')->whereNull('deleted_at')], 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date', 'reason' => 'required|string|min:5|max:4000', 'document' => 'required_if:kind,overtime|nullable|file|mimes:jpg,jpeg,png,pdf|max:15360', 'contact' => 'nullable|string|max:100', 'covering' => 'nullable|string|max:100', 'minutes' => 'nullable|integer|min:1|max:1440']);
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
        if ($d['kind'] === 'overtime' && ! $r->minutes) {
            W::fail('Enter total overtime minutes for this date range.');
        }
        $upload = $d['document'] ?? null;
        unset($d['document']);
        $path = $upload ? $upload->store('application-documents', 'local') : null;
        try {
            $id = DB::transaction(function () use ($d, $uid, $end, $path, $upload) {
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
                    $t = DB::table('leave_types')->where('id', $d['leave_type_id'])->whereNull('deleted_at')->lockForUpdate()->first();
                    abort_unless($t, 422, 'Leave type is no longer available.');
                    $used = DB::table('applications')->where('user_id', $uid)->where('leave_type_id', $t->id)->whereIn('status', ['pending', 'approved'])->whereYear('start_date', substr($d['start_date'], 0, 4))->sum('days');
                    if (! $days || $days + $used > $t->annual_limit) {
                        W::fail('Insufficient leave balance or no working days selected.');
                    }
                }
                $reviewers = W::reviewers($uid);
                $id = DB::table('applications')->insertGetId([...$d, 'document' => $path, 'document_name' => $upload ? basename($upload->getClientOriginalName()) : null, 'leave_type_id' => $d['kind'] === 'leave' ? $d['leave_type_id'] : null, 'minutes' => $d['kind'] === 'overtime' ? $d['minutes'] : null, 'end_date' => $end, 'user_id' => $uid, 'days' => $days, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                foreach ($reviewers as $rid) {
                    DB::table('approvals')->insert(['application_id' => $id, 'reviewer_id' => $rid, 'status' => 'pending']);
                    User::find($rid)->notify(new ApplicationNotice($id, 'A request is waiting for your review.'));
                }W::audit($uid, 'application.submitted', (string) $id);

                return $id;
            });

        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $e;
        }

        return response()->json(['id' => $id, 'message' => 'Submitted for approval'], 201);
    }

    public function decision(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission($r->input('decision') === 'approved' ? 'applications.approve' : 'applications.reject'), 403);
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
            User::find($a->user_id)?->notify(new ApplicationNotice($id, 'Your request status: '.$status));
        });

        return ['message' => 'Decision recorded'];
    }

    public function cancel(Request $r, int $id)
    {
        DB::transaction(function () use ($r, $id) {
            $a = DB::table('applications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($a, 404);
            $canCancel = $a->user_id === $r->user()->id ? $r->user()->hasPermission('applications.cancel_self') : ($r->user()->hasPermission('applications.cancel_any') || ($r->user()->hasPermission('applications.cancel_team') && in_array((int) $a->user_id, Visibility::teamUsers($r->user()), true)));
            abort_unless($canCancel, 403);
            abort_unless($a->status === 'pending' || ($a->kind === 'home_office' && $a->status === 'approved'), 409, 'Only pending requests or active home-office arrangements can be cancelled');
            DB::table('applications')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            W::audit($r->user()->id, 'application.cancelled', (string) $id);
            foreach (DB::table('approvals')->where('application_id', $id)->pluck('reviewer_id')->push($a->user_id)->unique() as $uid) {
                User::find($uid)?->notify(new ApplicationNotice($id, 'This request was cancelled or its home-office arrangement revoked.'));
            }
        });

        return ['message' => 'Cancelled'];
    }

    public function deleteFace(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('face.reset'), 403);
        DB::transaction(function () use ($id) {
            DB::table('users')->where('id', $id)->lockForUpdate()->first();
            DB::table('face_profiles')->where('user_id', $id)->delete();
            DB::table('face_challenges')->where(fn ($q) => $q->where('user_id', $id)->orWhere('subject_id', $id))->update(['expires_at' => now()]);
        });
        W::audit($r->user()->id, 'face.deleted', (string) $id);

        return ['message' => 'Face template deleted'];
    }

    public function punch(Request $r)
    {
        abort_unless($r->user()->hasPermission($r->input('action') === 'in' ? 'attendance.check_in' : 'attendance.check_out'), 403);
        $d = $r->validate(['action' => 'required|in:in,out', 'challenge_id' => 'required|uuid']);
        $uid = $r->user()->id;

        return DB::transaction(function () use ($uid, $d) {
            DB::table('users')->where('id', $uid)->lockForUpdate()->first();
            $proof = FaceProof::consume($uid, $d['challenge_id'], $d['action']);
            $distance = $proof->decoded['distance'];
            abort_unless($proof->photo && Storage::disk('local')->exists($proof->photo), 422, 'Camera verification expired. Start again.');
            $today = now('Asia/Dhaka')->toDateString();
            $open = DB::table('attendances')->where('user_id', $uid)->where('work_date', $today)->whereNull('check_out')->first();
            $existing = DB::table('attendances')->where('user_id', $uid)->where('work_date', $today)->first();
            if ($d['action'] === 'in') {
                abort_if($open || $existing, 409, 'Already checked in today or a previous shift is still open.');
                $leave = DB::table('applications')->where('user_id', $uid)->where('kind', 'leave')->where('status', 'approved')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->exists();
                abort_if($leave, 422, 'You are on approved leave.');
                $home = DB::table('applications')->where('user_id', $uid)->where('kind', 'home_office')->where('status', 'approved')->where('start_date', '<=', $today)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))->exists();
                $teamIds = DB::table('team_user')->where('user_id', $uid)->pluck('team_id');
                $teamHome = DB::table('team_home_office')->whereNull('cancelled_at')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->where(fn ($q) => $q->whereIn('team_id', $teamIds)->orWhere(fn ($q) => $q->whereNull('team_id')->whereRaw($teamIds->isNotEmpty() ? '1=1' : '1=0')))->exists();
                $home = $home || $teamHome;
                $path = 'attendance/'.Str::uuid().'.jpg';
                abort_unless(Storage::disk('local')->copy($proof->photo, $path), 503, 'Could not save attendance photo. Try again.');
                try {
                    [$slotStart,$slotEnd] = AttendanceReport::slot($uid);
                    $recordId = DB::table('attendances')->insertGetId(['slot_start' => $slotStart, 'slot_end' => $slotEnd, 'user_id' => $uid, 'work_date' => $today, 'check_in' => now(), 'mode' => $home ? 'home_office' : 'office', 'in_photo' => $path, 'in_distance' => $distance, 'in_capture_hash' => $proof->capture_hash]);
                } catch (\Throwable $e) {
                    Storage::disk('local')->delete($path);
                    throw $e;
                }
            } else {
                abort_unless($open, 409, 'No open shift to check out.');
                $recordId = $open->id;
                $path = 'attendance/'.Str::uuid().'.jpg';
                abort_unless(Storage::disk('local')->copy($proof->photo, $path), 503, 'Could not save attendance photo. Try again.');
                try {
                    DB::table('attendances')->where('id', $open->id)->update(['check_out' => now(), 'out_photo' => $path, 'out_distance' => $distance, 'out_capture_hash' => $proof->capture_hash]);
                } catch (\Throwable $e) {
                    Storage::disk('local')->delete($path);
                    throw $e;
                }
            }
            W::audit($uid, 'attendance.'.$d['action'], $today);

            return ['message' => $d['action'] === 'in' ? 'Checked in successfully' : 'Checked out successfully', 'attendance' => FaceProof::attendance(DB::table('attendances')->where('id', $recordId)->first())];
        });
    }

    public function attendance(Request $r)
    {
        $d = $r->validate(['start_date' => 'nullable|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d', 'user_id' => 'nullable|integer|exists:users,id']);
        $start = $d['start_date'] ?? now('Asia/Dhaka')->startOfMonth()->toDateString();
        $end = $d['end_date'] ?? now('Asia/Dhaka')->toDateString();
        abort_if($end < $start || CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > 366, 422, 'Select a date range up to one year.');
        abort_if(isset($d['user_id']) && ! in_array((int) $d['user_id'], $this->visible($r), true), 403);
        $uids = isset($d['user_id']) ? [(int) $d['user_id']] : $this->visible($r);
        $report = AttendanceReport::build($uids, $start, $end);
        foreach ($report['data'] as &$row) {
            $row['display_status'] = $row['leave_name'] ?: ($row['status'] === 'absent' ? 'Absent' : ($row['mode'] === 'leave' ? 'Leave' : 'Present'));
            $row['can_view_photo'] = $r->user()->hasPermission('attendance.photo_view_all') || ($r->user()->hasPermission('attendance.photo_view_team') && in_array((int) $row['user_id'], Visibility::teamUsers($r->user()), true)) || ((int) $row['user_id'] === $r->user()->id && $r->user()->hasPermission('attendance.photo_view_self'));
        }
        unset($row);
        [$report['data'],$facets] = TableFilters::array($r, $report['data'], ['name', 'work_date', 'check_in', 'check_out', 'mode', 'leave_name', 'status', 'slot_start', 'slot_end', 'working_minutes', 'actual_minutes', 'display_status']);
        $report['facets'] = $facets;
        $page = max(1, (int) $r->query('page', 1));
        $count = count($report['data']);
        $report['data'] = array_slice($report['data'], ($page - 1) * 100, 100);

        return [...$report, 'current_page' => $page, 'last_page' => max(1, (int) ceil($count / 100)), 'total' => $count];
    }

    public function saveSlot(Request $r, string $kind, int $id)
    {
        abort_unless($r->user()->hasPermission($kind === 'users' ? 'work_slots.update_user' : 'work_slots.update_team'), 403);
        abort_unless(in_array($kind, ['users', 'teams']), 404);
        abort_unless(DB::table($kind)->where('id', $id)->exists(), 404);
        $d = $r->validate(['slot_start' => 'nullable|date_format:H:i', 'slot_end' => 'nullable|date_format:H:i', 'slot_team_id' => 'nullable|integer|exists:teams,id']);
        $from = $d['slot_start'] ?? null;
        $to = $d['slot_end'] ?? null;
        abort_if((bool) $from !== (bool) $to || ($from && $from >= $to), 422, 'Enter both slot times, with end after start (same day).');
        if ($kind === 'users' && ! empty($d['slot_team_id'])) {
            abort_unless(DB::table('team_user')->where('user_id', $id)->where('team_id', $d['slot_team_id'])->exists(), 422, 'Select a team this user belongs to.');
        }
        DB::table($kind)->where('id', $id)->update(['slot_start' => $from, 'slot_end' => $to, ...($kind === 'users' ? ['slot_team_id' => $d['slot_team_id'] ?? null] : [])]);
        W::audit($r->user()->id, 'slot.updated', $kind.':'.$id);

        return ['message' => 'Work slot saved. Changes apply to future check-ins.'];
    }

    public function photo(Request $r, int $id, string $side)
    {
        abort_unless(in_array($side, ['in', 'out']), 404);
        $a = DB::table('attendances')->where('id', $id)->first();
        $uids = [$r->user()->id];
        if ($r->user()->hasPermission('attendance.photo_view_all')) {
            $uids = DB::table('users')->pluck('id')->all();
        } elseif ($r->user()->hasPermission('attendance.photo_view_team')) {
            $uids = DB::table('team_user')->whereIn('team_id', DB::table('team_user')->where('user_id', $r->user()->id)->select('team_id'))->pluck('user_id')->push($r->user()->id)->all();
        }
        abort_unless($a && in_array($a->user_id, $uids), 404);
        $path = $a->{$side.'_photo'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function users(Request $r)
    {
        abort_unless($r->user()->hasPermission('users.view'), 403);

        return User::with(['assignedRole.permissions', 'directPermissions'])->orderBy('name')->get()->map(function ($u) use ($r) {
            $u->can_view_profile = $u->id === $r->user()->id ? $r->user()->hasPermission('profile.view_self') : ($r->user()->hasPermission('profile.view_all') || ($r->user()->hasPermission('profile.view_team') && in_array((int) $u->id, Visibility::teamUsers($r->user()), true)));
            $u->direct_permission_ids = $u->directPermissions->pluck('id');
            $u->face_enrolled = DB::table('face_profiles')->where('user_id', $u->id)->where('verification_version', '>=', 2)->exists();
            $u->face_reset_available = DB::table('face_profiles')->where('user_id', $u->id)->exists();

            return $u;
        });
    }

    public function saveUser(Request $r, ?int $id = null)
    {
        abort_unless($r->user()->hasPermission($id ? 'users.update' : 'users.create'), 403);
        $d = $r->validate(['name' => 'required|string|max:150', 'email' => ['required', 'email', Rule::unique('users')->ignore($id)], 'role' => ['required', Rule::exists('roles', 'slug')], 'password' => ($id ? 'nullable' : 'required').'|string|min:6', 'active' => 'required|boolean', 'designation' => 'nullable|string|max:150', 'phone' => 'nullable|string|max:50']);
        $targetRole = Role::where('slug', $d['role'])->firstOrFail();
        $existing = $id ? User::findOrFail($id) : null;
        abort_if(! $r->user()->isOwner() && ((! $existing && $targetRole->slug !== 'employee') || ($existing && $existing->role_id !== $targetRole->id) || ($existing && $existing->isOwner())), 403, 'Only Owner can change role assignments or manage an Owner.');
        $d['role_id'] = $targetRole->id;
        if ($id === $r->user()->id && (! $d['active'] || $d['role'] !== 'owner')) {
            W::fail('You cannot deactivate or demote your own owner account.');
        }if (empty($d['password'])) {
            unset($d['password']);
        }$u = $id ? User::findOrFail($id) : new User;
        $u->fill($d)->save();
        if ($id && (! empty($d['password']) || ! $d['active'])) {
            BrowserAuth::revokeUser($u->id);
        }
        W::audit($r->user()->id, 'user.saved', (string) $u->id);

        return $u;
    }

    public function teams(Request $r)
    {
        $q = DB::table('teams');
        if (! $r->user()->isOwner() && ! $r->user()->hasPermission('teams.members_update')) {
            $q->whereIn('id', DB::table('team_user')->where('user_id', $r->user()->id)->select('team_id'));
        }

        return $q->get()->map(function ($t) {
            $t->members = DB::table('team_user')->join('users', 'users.id', '=', 'team_user.user_id')->where('team_id', $t->id)->select('users.id', 'users.name', 'team_role')->get();

            return $t;
        });
    }

    public function saveTeam(Request $r)
    {
        abort_unless($r->user()->hasPermission('teams.create'), 403);
        $d = $r->validate(['name' => 'required|string|max:100|unique:teams']);
        $id = DB::table('teams')->insertGetId([...$d, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id];
    }

    public function members(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('teams.members_update'), 403);
        abort_unless(DB::table('teams')->where('id', $id)->exists(), 404);
        $d = $r->validate(['members' => 'present|array', 'members.*.user_id' => 'required|distinct|exists:users,id', 'members.*.team_role' => ['required', Rule::exists('roles', 'slug')]]);
        DB::transaction(function () use ($d, $id, $r) {
            DB::table('team_user')->where('team_id', $id)->delete();
            foreach ($d['members'] as $m) {
                DB::table('team_user')->insert([...$m, 'team_id' => $id, 'team_role_id' => Role::where('slug', $m['team_role'])->value('id')]);
            }W::audit($r->user()->id, 'team.members.updated', (string) $id);
        });

        return ['message' => 'Team assignments saved'];
    }

    public function saveLeaveType(Request $r, ?int $id = null)
    {
        abort_unless($r->user()->hasPermission($id ? 'leave_types.update' : 'leave_types.create'), 403);
        $d = $r->validate(['name' => ['required', 'string', 'max:100', Rule::unique('leave_types')->ignore($id)], 'annual_limit' => 'required|integer|min:0|max:366']);
        if ($id) {
            abort_unless(DB::table('leave_types')->where('id', $id)->whereNull('deleted_at')->exists(), 404);
            DB::table('leave_types')->where('id', $id)->update([...$d, 'updated_at' => now()]);
        } else {
            DB::table('leave_types')->insert([...$d, 'created_at' => now(), 'updated_at' => now()]);
        }
        W::audit($r->user()->id, 'leave_type.saved', (string) ($id ?? $d['name']));

        return ['message' => 'Leave type saved'];
    }

    public function deleteLeaveType(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('leave_types.delete'), 403);
        abort_unless(DB::table('leave_types')->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        DB::table('leave_types')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        W::audit($r->user()->id, 'leave_type.deleted', (string) $id);

        return ['message' => 'Leave type deleted; past requests retained'];
    }

    public function deleteUser(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('users.delete'), 403);
        abort_if($id === $r->user()->id, 422, 'You cannot delete your own account.');
        DB::transaction(function () use ($r, $id) {
            $u = User::lockForUpdate()->findOrFail($id);
            abort_if($u->isOwner() && ! $r->user()->isOwner(), 403);
            abort_if(DB::table('approvals')->join('applications', 'applications.id', '=', 'approvals.application_id')->where('reviewer_id', $id)->where('approvals.status', 'pending')->where('applications.status', 'pending')->exists(), 422, 'Resolve pending reviews before deleting this user.');
            abort_if(DB::table('applications')->where('user_id', $id)->where('status', 'pending')->exists(), 422, 'Resolve or cancel pending applications before deleting this user.');
            $u->update(['active' => false]);
            $u->delete();
            BrowserAuth::revokeUser($id);
            DB::table('team_user')->where('user_id', $id)->delete();
            DB::table('face_profiles')->where('user_id', $id)->delete();
            DB::table('face_challenges')->where(fn ($q) => $q->where('user_id', $id)->orWhere('subject_id', $id))->update(['expires_at' => now()]);
            W::audit($r->user()->id, 'user.deleted', (string) $id);
        });

        return ['message' => 'User deleted; historical records retained'];
    }

    public function importUsers(Request $r)
    {
        abort_unless($r->user()->hasPermission('users.import'), 403);
        $r->validate(['file' => 'required|file|max:2048']);
        $f = fopen($r->file('file')->path(), 'r');
        $header = fgetcsv($f, 0, ',', '"', '');
        abort_unless($header, 422, 'CSV is empty.');
        $header = array_map(fn ($v) => trim($v, " \t\n\r\0\x0B\xEF\xBB\xBF"), $header);
        $allowed = ['name', 'email', 'password', 'role', 'active', 'designation', 'phone'];
        abort_unless(count($header) === count(array_unique($header)) && ! array_diff($header, $allowed) && ! array_diff(['name', 'email', 'password'], $header), 422, 'Use CSV columns name,email,password,role,active,designation,phone.');
        $rows = [];
        $emails = [];
        $line = 1;
        while (($values = fgetcsv($f, 0, ',', '"', '')) !== false) {
            $line++;
            if ($values === [null]) {
                continue;
            }
            abort_if(count($rows) >= 500 || count($values) !== count($header), 422, "Invalid CSV row $line (maximum 500 users).");
            $row = array_combine($header, $values);
            $row['email'] = strtolower(trim($row['email']));
            $row['role'] = $row['role'] ?? 'employee';
            abort_if($row['role'] !== 'employee' && ! $r->user()->isOwner(), 403, 'Only Owner can import non-Member role assignments.');
            $row['active'] = $row['active'] ?? 'true';
            abort_unless(in_array(strtolower($row['active']), ['true', 'false', '1', '0'], true), 422, "Invalid active value on row $line.");
            $row['active'] = in_array(strtolower($row['active']), ['true', '1'], true);
            $v = validator($row, ['name' => 'required|string|max:150', 'email' => 'required|email|max:255|unique:users', 'password' => 'required|string|min:6', 'role' => ['required', Rule::exists('roles', 'slug')], 'designation' => 'nullable|string|max:150', 'phone' => 'nullable|string|max:50']);
            abort_if($v->fails(), 422, "CSV row $line: ".$v->errors()->first());
            abort_if(isset($emails[$row['email']]), 422, "Duplicate email on row $line.");
            $emails[$row['email']] = true;
            $rows[] = $row;
        }
        fclose($f);
        abort_unless($rows, 422, 'CSV has no users.');
        DB::transaction(function () use ($rows, $r) {
            foreach ($rows as $row) {
                User::create($row);
            } W::audit($r->user()->id, 'users.imported', (string) count($rows));
        });

        return ['message' => count($rows).' users imported', 'count' => count($rows)];
    }

    public function document(Request $r, int $id)
    {
        $a = DB::table('applications')->where('id', $id)->first();
        abort_unless($a && Visibility::application($r->user(), $a), 404);
        abort_unless($a->document && Storage::disk('local')->exists($a->document), 404);

        return Storage::disk('local')->download($a->document, $a->document_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function calendar()
    {
        return DB::table('calendar_days')->orderBy('date')->get();
    }

    public function saveCalendar(Request $r)
    {
        abort_unless($r->user()->hasPermission(DB::table('calendar_days')->where('date', $r->input('date'))->exists() ? 'calendar.update' : 'calendar.create'), 403);
        $d = $r->validate(['date' => 'required|date_format:Y-m-d', 'name' => 'required|string|max:150', 'is_working' => 'required|boolean']);
        DB::table('calendar_days')->updateOrInsert(['date' => $d['date']], $d);

        return ['message' => 'Calendar updated'];
    }

    public function deleteCalendar(Request $r, int $id)
    {
        abort_unless($r->user()->hasPermission('calendar.delete'), 403);
        DB::table('calendar_days')->where('id', $id)->delete();

        return ['message' => 'Calendar override removed'];
    }

    public function audit(Request $r)
    {
        abort_unless($r->user()->hasPermission('audit.view'), 403);

        $base = DB::table('audit_logs')->join('users', 'users.id', '=', 'audit_logs.actor_id');
        [$query,$facets] = TableFilters::query($r, $base, ['name' => 'users.name', 'action' => 'audit_logs.action', 'subject' => 'audit_logs.subject', 'created_at' => 'audit_logs.created_at']);
        $page = $query->select('audit_logs.*', 'users.name')->paginate(100)->toArray();

        return [...$page, 'facets' => $facets];
    }
}
