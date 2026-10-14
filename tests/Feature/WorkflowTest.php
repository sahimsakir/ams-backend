<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AttendanceReport;
use App\Services\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    private User $lead;

    private User $pm;

    private int $leave;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->owner = $this->makeUser('owner');
        $this->employee = $this->makeUser('employee');
        $this->lead = $this->makeUser('team_lead');
        $this->pm = $this->makeUser('pm');
        $team = DB::table('teams')->insertGetId(['name' => 'Engineering']);
        foreach ([[$this->employee, 'employee'], [$this->lead, 'team_lead'], [$this->pm, 'pm']] as [$u,$role]) {
            DB::table('team_user')->insert(['team_id' => $team, 'user_id' => $u->id, 'team_role' => $role]);
        }
        $this->leave = DB::table('leave_types')->insertGetId(['name' => 'Casual', 'annual_limit' => 10]);
    }

    private function makeUser(string $role): User
    {
        $u = User::create(['name' => $role, 'email' => uniqid().'@example.com', 'password' => 'secure-password-123', 'role' => $role, 'active' => true]);
        DB::table('face_profiles')->insert(['user_id' => $u->id, 'embedding' => Crypt::encryptString(json_encode(['templates' => [array_fill(0, 128, 0.0)]])), 'verification_version' => 2, 'enrolled_by' => $u->id]);

        return $u;
    }

    private function asUser(User $u): static
    {
        $token = bin2hex(random_bytes(32));
        DB::table('access_tokens')->insert(['user_id' => $u->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);

        return $this->withToken($token);
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['kind' => 'leave', 'leave_type_id' => $this->leave, 'start_date' => '2026-10-18', 'end_date' => '2026-10-18', 'reason' => 'Personal appointment'], $extra);
    }

    private function apply(array $extra = []): int
    {
        return $this->asUser($this->employee)->postJson('/api/applications', $this->payload($extra))->assertCreated()->json('id');
    }

    public function test_employee_cannot_create_people(): void
    {
        $this->asUser($this->employee)->postJson('/api/users', [])->assertForbidden();
    }

    public function test_both_reviewers_must_approve(): void
    {
        $id = $this->apply();
        $this->asUser($this->lead)->postJson("/api/applications/$id/decision", ['decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('applications', ['id' => $id, 'status' => 'pending']);
        $this->asUser($this->pm)->postJson("/api/applications/$id/decision", ['decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('applications', ['id' => $id, 'status' => 'approved']);
    }

    public function test_self_approval_is_forbidden(): void
    {
        $id = $this->apply();
        $this->asUser($this->employee)->postJson("/api/applications/$id/decision", ['decision' => 'approved'])->assertForbidden();
    }

    public function test_unassigned_owner_cannot_bypass_approval(): void
    {
        $id = $this->apply();
        $this->asUser($this->owner)->postJson("/api/applications/$id/decision", ['decision' => 'approved'])->assertForbidden();
    }

    public function test_rejection_requires_reason_and_is_terminal(): void
    {
        $id = $this->apply();
        $this->asUser($this->lead)->postJson("/api/applications/$id/decision", ['decision' => 'rejected'])->assertUnprocessable();
        $this->postJson("/api/applications/$id/decision", ['decision' => 'rejected', 'comment' => 'No coverage'])->assertOk();
        $this->asUser($this->pm)->postJson("/api/applications/$id/decision", ['decision' => 'approved'])->assertStatus(409);
    }

    public function test_pending_requests_reserve_balance(): void
    {
        DB::table('leave_types')->where('id', $this->leave)->update(['annual_limit' => 1]);
        $this->apply();
        $this->asUser($this->employee)->postJson('/api/applications', $this->payload(['start_date' => '2026-10-19', 'end_date' => '2026-10-19']))->assertUnprocessable();
    }

    public function test_cancel_releases_reservation(): void
    {
        DB::table('leave_types')->where('id', $this->leave)->update(['annual_limit' => 1]);
        $id = $this->apply();
        $this->asUser($this->employee)->postJson("/api/applications/$id/cancel")->assertOk();
        $this->apply();
    }

    public function test_overlap_is_rejected(): void
    {
        $this->apply();
        $this->asUser($this->employee)->postJson('/api/applications', $this->payload())->assertUnprocessable();
    }

    public function test_cross_year_leave_rejected(): void
    {
        $this->asUser($this->employee)->postJson('/api/applications', $this->payload(['start_date' => '2026-12-31', 'end_date' => '2027-01-03']))->assertUnprocessable();
    }

    public function test_weekend_and_calendar_override(): void
    {
        $this->assertSame(1, Workflow::days('2026-10-16', '2026-10-18'));
        DB::table('calendar_days')->insert(['date' => '2026-10-17', 'name' => 'Replacement', 'is_working' => true]);
        $this->assertSame(2, Workflow::days('2026-10-16', '2026-10-18'));
    }

    public function test_open_ended_home_office_requires_approval(): void
    {
        $id = $this->apply(['kind' => 'home_office', 'leave_type_id' => null, 'end_date' => null]);
        $this->assertDatabaseHas('applications', ['id' => $id, 'end_date' => null, 'status' => 'pending']);
    }

    public function test_multiple_teams_deduplicate_reviewers(): void
    {
        $t = DB::table('teams')->insertGetId(['name' => 'Platform']);
        foreach ([[$this->employee, 'employee'], [$this->lead, 'team_lead'], [$this->pm, 'pm']] as [$u,$role]) {
            DB::table('team_user')->insert(['team_id' => $t, 'user_id' => $u->id, 'team_role' => $role]);
        }$id = $this->apply();
        $this->assertSame(2, DB::table('approvals')->where('application_id', $id)->count());
    }

    public function test_unrelated_employee_cannot_read_requests(): void
    {
        $this->apply();
        $other = $this->makeUser('employee');
        $this->asUser($other)->getJson('/api/applications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_login_and_logout_revoke_token(): void
    {
        $res = $this->postJson('/api/login', ['email' => $this->employee->email, 'password' => 'secure-password-123'])->assertOk();
        $this->withToken($res->json('token'))->postJson('/api/logout')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_deactivated_user_cannot_login(): void
    {
        $this->employee->update(['active' => false]);
        $this->postJson('/api/login', ['email' => $this->employee->email, 'password' => 'secure-password-123'])->assertUnauthorized();
    }

    private function face(float $coordinate = 0.0, int $status = 200): void
    {
        Storage::fake('local');
        DB::table('face_profiles')->where('user_id', $this->employee->id)->delete();
        DB::table('face_profiles')->insert(['user_id' => $this->employee->id, 'embedding' => Crypt::encryptString(json_encode(['templates' => [array_fill(0, 128, 0.0)]])), 'verification_version' => 2, 'enrolled_by' => $this->owner->id]);
        Http::fake(['*' => Http::response(['embedding' => array_fill(0, 128, $coordinate), 'baseline' => ['yaw' => 0, 'pitch' => .4, 'ear' => .3], 'distance' => $coordinate, 'live_score' => .99], $status)]);
    }

    private function punch(string $action)
    {
        $this->asUser($this->employee);
        $c = $this->postJson('/api/face/challenges', ['purpose' => $action, 'consent' => true])->assertOk()->json();
        $photo = UploadedFile::fake()->image('capture.jpg');
        file_put_contents($photo->path(), random_bytes(16), FILE_APPEND);
        $res = $this->post('/api/face/challenges/'.$c['id'].'/capture', ['nonce' => $c['nonce'], 'photo' => $photo], ['Accept' => 'application/json']);
        if ($res->status() !== 200) {
            return $res;
        }
        $c = $res->json();

        return $this->postJson('/api/attendance', ['action' => $action, 'challenge_id' => $c['id']]);
    }

    public function test_check_in_and_duplicate_guard(): void
    {
        $this->face();
        $this->punch('in')->assertOk();
        $this->punch('in')->assertStatus(409);
        $this->punch('out')->assertOk();
        $this->punch('out')->assertStatus(409);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_face_mismatch_fails_closed(): void
    {
        $this->face(1.0);
        $this->punch('in')->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_face_service_failure_creates_no_record(): void
    {
        $this->face(0.0, 503);
        $this->punch('in')->assertStatus(503);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_home_office_does_not_fabricate_attendance(): void
    {
        $id = $this->apply(['kind' => 'home_office', 'leave_type_id' => null, 'end_date' => null]);
        foreach ([$this->lead, $this->pm] as $u) {
            $this->asUser($u)->postJson("/api/applications/$id/decision", ['decision' => 'approved'])->assertOk();
        }$this->assertDatabaseCount('attendances', 0);
    }

    public function test_api_traffic_does_not_consume_login_rate_limit(): void
    {
        $this->asUser($this->owner);
        for ($i = 0; $i < 8; $i++) {
            $this->getJson('/api/dashboard')->assertOk();
        }
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => $this->employee->email, 'password' => 'secure-password-123'])->assertOk();
    }

    public function test_owner_manages_leave_types_and_archived_type_cannot_be_used(): void
    {
        $this->asUser($this->employee)->deleteJson('/api/leave-types/'.$this->leave)->assertForbidden();
        $this->asUser($this->owner)->putJson('/api/leave-types/'.$this->leave, ['name' => 'Updated', 'annual_limit' => 12])->assertOk();
        $this->deleteJson('/api/leave-types/'.$this->leave)->assertOk();
        $this->getJson('/api/leave-types')->assertJsonCount(0);
        $this->asUser($this->employee)->postJson('/api/applications', $this->payload())->assertUnprocessable();
    }

    public function test_user_deletion_revokes_access_and_preserves_account_history(): void
    {
        $this->asUser($this->owner)->deleteJson('/api/users/'.$this->owner->id)->assertUnprocessable();
        $this->deleteJson('/api/users/'.$this->employee->id)->assertOk();
        $this->assertSoftDeleted('users', ['id' => $this->employee->id]);
        $this->assertDatabaseMissing('face_profiles', ['user_id' => $this->employee->id]);
        $this->postJson('/api/login', ['email' => $this->employee->email, 'password' => 'secure-password-123'])->assertUnauthorized();
    }

    public function test_csv_import_is_atomic_and_owner_only(): void
    {
        $csv = "name,email,password,role,active\nNew User,new@example.com,long-password-123,employee,true\nBad,bad-email,long-password-123,employee,true\n";
        $file = UploadedFile::fake()->createWithContent('users.csv', $csv);
        $this->asUser($this->employee)->post('/api/users/import', ['file' => $file], ['Accept' => 'application/json'])->assertForbidden();
        $this->asUser($this->owner)->post('/api/users/import', ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $file = UploadedFile::fake()->createWithContent('users.csv', "name,email,password\nNew User,new@example.com,long-password-123\n");
        $this->post('/api/users/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('count', 1);
        $this->assertTrue(Hash::check('long-password-123', User::where('email', 'new@example.com')->first()->password));
    }

    public function test_overtime_date_range_document_is_private(): void
    {
        Storage::fake('local');
        $data = $this->payload(['kind' => 'overtime', 'leave_type_id' => null, 'end_date' => '2026-10-20', 'minutes' => 180, 'document' => UploadedFile::fake()->image('approval.png')]);
        $id = $this->asUser($this->employee)->post('/api/applications', $data, ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->get('/api/applications/'.$id.'/document')->assertOk();
        $this->asUser($this->lead)->get('/api/applications/'.$id.'/document')->assertOk();
        $other = $this->makeUser('employee');
        $this->asUser($other)->get('/api/applications/'.$id.'/document')->assertNotFound();
        $this->assertDatabaseHas('applications', ['id' => $id, 'minutes' => 180, 'end_date' => '2026-10-20']);
    }

    private function reportRecord(User $u, string $date, string $in, ?string $out, string $mode = 'office'): int
    {
        return DB::table('attendances')->insertGetId(['user_id' => $u->id, 'work_date' => $date, 'check_in' => $in, 'check_out' => $out, 'mode' => $mode, 'in_photo' => 'private.jpg', 'in_distance' => 0.1, 'slot_start' => '09:00', 'slot_end' => '18:00']);
    }

    public function test_only_owner_can_see_other_attendance_and_photos(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Asia/Dhaka'));
        $id = $this->reportRecord($this->employee, '2026-10-18', '2026-10-18 03:00:00', '2026-10-18 12:00:00');
        foreach ([$this->lead, $this->pm] as $u) {
            $this->asUser($u)->getJson('/api/attendance')->assertJsonCount(0, 'data');
            $this->getJson('/api/attendance?user_id='.$this->employee->id)->assertForbidden();
            $this->get('/api/attendance/'.$id.'/photo/in')->assertNotFound();
        }
        $this->asUser($this->owner)->getJson('/api/attendance')->assertJsonCount(1, 'data');
    }

    public function test_report_metrics_clip_hours_and_include_leave_and_missing_exit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Asia/Dhaka'));
        $this->reportRecord($this->employee, '2026-10-18', '2026-10-18 02:30:00', '2026-10-18 12:30:00');
        $this->reportRecord($this->employee, '2026-10-19', '2026-10-19 03:30:00', '2026-10-19 11:30:00', 'home_office');
        $this->reportRecord($this->employee, '2026-10-20', '2026-10-20 03:00:00', null);
        DB::table('applications')->insert(['user_id' => $this->employee->id, 'kind' => 'leave', 'leave_type_id' => $this->leave, 'start_date' => '2026-10-21', 'end_date' => '2026-10-21', 'days' => 1, 'reason' => 'Personal leave', 'status' => 'approved']);
        $r = $this->asUser($this->employee)->getJson('/api/attendance?start_date=2026-10-18&end_date=2026-10-21')->assertOk();
        $r->assertJsonPath('summary.working_days', 4)->assertJsonPath('summary.late_days', 1)->assertJsonPath('summary.leave_days', 1)->assertJsonPath('summary.home_office_days', 1)->assertJsonPath('summary.missing_exits', 1)->assertJsonPath('summary.avg_working_minutes', 540)->assertJsonPath('summary.actual_avg_minutes', 510)->assertJsonPath('summary.early_arrival_avg_minutes', 10)->assertJsonPath('summary.late_departure_avg_minutes', 15)->assertJsonPath('data.0.leave_name', 'Casual');
        $this->getJson('/api/attendance?start_date=2026-10-19&end_date=2026-10-19')->assertJsonCount(1, 'data');
        $this->getJson('/api/attendance?start_date=2026-10-21&end_date=2026-10-18')->assertUnprocessable();
    }

    public function test_owner_work_slots_inherit_team_with_user_override(): void
    {
        $team = DB::table('teams')->first();
        $this->asUser($this->employee)->putJson('/api/work-slots/teams/'.$team->id, ['slot_start' => '10:00', 'slot_end' => '19:00'])->assertForbidden();
        $this->asUser($this->owner)->putJson('/api/work-slots/teams/'.$team->id, ['slot_start' => '10:00', 'slot_end' => '19:00'])->assertOk();
        $this->assertSame(['10:00', '19:00'], AttendanceReport::slot($this->employee->id));
        $this->putJson('/api/work-slots/users/'.$this->employee->id, ['slot_start' => '08:00', 'slot_end' => '17:00', 'slot_team_id' => $team->id])->assertOk();
        $this->assertSame(['08:00', '17:00'], AttendanceReport::slot($this->employee->id));
        $this->putJson('/api/work-slots/users/'.$this->employee->id, ['slot_start' => '18:00', 'slot_end' => '09:00'])->assertUnprocessable();
        $this->putJson('/api/work-slots/users/'.$this->employee->id, ['slot_start' => null, 'slot_end' => null, 'slot_team_id' => $team->id])->assertOk();
        $this->assertSame(['10:00', '19:00'],AttendanceReport::slot($this->employee->id));
    }
}
