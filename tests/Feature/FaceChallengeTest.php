<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FaceProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FaceChallengeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $serviceStatus = 200;

    private ?array $serviceBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response($this->serviceBody ?? ['embedding' => array_fill(0, 128, 0.0), 'baseline' => ['yaw' => 0, 'pitch' => .4, 'ear' => .3], 'distance' => 0, 'live_score' => .99, 'live' => true, 'frames_checked' => 4, 'model' => 'minivision-b6d5f04-ensemble-v1'], $this->serviceStatus));
        $this->user = User::create(['name' => 'Self', 'email' => 'self@example.com', 'password' => 'secure-password-123', 'role' => 'employee', 'active' => true]);
        $this->signIn($this->user);
    }

    private function signIn(User $u): void
    {
        $this->passportSignIn($u);
    }

    private function start(string $purpose = 'enroll'): array
    {
        return $this->postJson('/api/face/challenges', ['purpose' => $purpose, 'consent' => true])->assertOk()->json();
    }

    private function frames(): array
    {
        $frames = [];
        for ($i = 0; $i < 4; $i++) {
            $f = UploadedFile::fake()->image('capture.jpg');
            file_put_contents($f->path(), random_bytes(16), FILE_APPEND);
            $frames[] = $f;
        }

        return $frames;
    }

    private function step(array $c, ?array $frames = null)
    {
        $this->travel(2)->seconds();

        if (($c['pose'] ?? null) === 'capture') {
            $frames ??= $this->frames();

            return $this->post('/api/face/challenges/'.$c['id'].'/capture', ['nonce' => $c['nonce'], 'photo' => $frames[0], 'frames' => $frames], ['Accept' => 'application/json']);
        }

        return $this->post('/api/face/challenges/'.$c['id'].'/step', ['nonce' => $c['nonce'], 'frames' => $frames ?? $this->frames()], ['Accept' => 'application/json']);
    }

    private function complete(string $purpose = 'enroll'): array
    {
        $c = $this->start($purpose);
        while (! $c['complete']) {
            $c = $this->step($c)->assertOk()->json();
        }

        return $c;
    }

    private function enroll(): void
    {
        $c = $this->complete();
        $this->postJson('/api/face/enroll', ['challenge_id' => $c['id']])->assertOk();
    }

    public function test_three_steps_are_required_and_user_enrolls_self(): void
    {
        $c = $this->start();
        $steps = json_decode(DB::table('face_challenges')->where('id', $c['id'])->value('steps'), true);
        $this->assertSame(['front', 'left', 'right'], $steps);
        $this->postJson('/api/face/enroll', ['challenge_id' => $c['id']])->assertUnprocessable();
        while (! $c['complete']) {
            $c = $this->step($c)->assertOk()->json();
        }
        $this->postJson('/api/face/enroll', ['challenge_id' => $c['id']])->assertOk()->assertJsonPath('face_enrolled', true);
        $this->assertDatabaseHas('face_profiles', ['user_id' => $this->user->id, 'enrolled_by' => $this->user->id, 'verification_version' => 2]);
        $this->getJson('/api/me')->assertJsonPath('face_enrolled', true);
        $this->postJson('/api/face/enroll', ['challenge_id' => $c['id']])->assertUnprocessable();
        $this->postJson('/api/face/challenges', ['purpose' => 'enroll', 'consent' => true])->assertStatus(409);
    }

    public function test_attendance_requires_enrollment_and_completed_camera_proof(): void
    {
        $this->postJson('/api/face/challenges', ['purpose' => 'in', 'consent' => true])->assertUnprocessable();
        $this->post('/api/attendance', ['action' => 'in', 'consent' => true, 'photo' => $this->frames()[0]], ['Accept' => 'application/json'])->assertForbidden();
        $c = $this->complete();
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertForbidden();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_completed_proof_is_bound_to_owner_purpose_and_single_use(): void
    {
        $this->enroll();
        $c = $this->complete('in');
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secure-password-123', 'role' => 'owner', 'active' => true]);
        DB::table('face_profiles')->insert(['user_id' => $other->id, 'embedding' => Crypt::encryptString(json_encode(['templates' => [array_fill(0, 128, 0.0)]])), 'verification_version' => 2, 'enrolled_by' => $other->id]);
        $this->signIn($other);
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertUnprocessable();
        $this->signIn($this->user);
        $this->postJson('/api/attendance', ['action' => 'out', 'challenge_id' => $c['id']])->assertUnprocessable();
        $response = $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertOk();
        $this->assertMatchesRegularExpression('/T.*Z$/', $response->json('attendance.check_in'));
        $this->assertArrayNotHasKey('in_photo', $response->json('attendance'));
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_frozen_frames_and_stale_nonce_do_not_advance_step(): void
    {
        $c = $this->start();
        $frames = $this->frames();
        $this->step($c, array_fill(0, 4, $frames[0]))->assertUnprocessable();
        Http::assertNothingSent();
        $next = $this->step($c, $frames)->assertOk()->json();
        $this->step($c)->assertStatus(409);
        $this->step($next, $frames)->assertUnprocessable();
        $this->assertDatabaseHas('face_challenges', ['id' => $c['id'], 'step' => 1]);
    }

    public function test_expiry_and_restart_invalidate_old_challenge(): void
    {
        $old = $this->start();
        $c = $this->start();
        $this->step($old)->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->step($c)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_reset_revokes_pending_attendance_proof(): void
    {
        $this->enroll();
        $c = $this->complete('in');
        $this->user->update(['role' => 'owner']);
        $this->deleteJson('/api/users/'.$this->user->id.'/face')->assertOk();
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertForbidden();
        $this->getJson('/api/me')->assertJsonPath('face_enrolled', false);
    }

    public function test_legacy_profile_requires_guided_upgrade(): void
    {
        DB::table('face_profiles')->insert(['user_id' => $this->user->id, 'embedding' => Crypt::encryptString(json_encode(array_fill(0, 128, 0.0))), 'enrolled_by' => $this->user->id]);
        $this->getJson('/api/me')->assertJsonPath('face_enrolled', false);
        $this->postJson('/api/face/challenges', ['purpose' => 'in', 'consent' => true])->assertUnprocessable();
        $this->enroll();
    }

    public function test_incomplete_failed_and_malformed_step_cannot_enroll(): void
    {
        $c = $this->start();
        $this->serviceStatus = 422;
        $this->serviceBody = ['detail' => 'Bad pose'];
        $this->step($c)->assertUnprocessable();
        $this->serviceStatus = 200;
        $this->serviceBody = ['embedding' => [0]];
        $this->step($c)->assertStatus(503);
        $this->postJson('/api/face/enroll', ['challenge_id' => $c['id']])->assertUnprocessable();
        $this->assertDatabaseCount('face_profiles', 0);
    }

    public function test_owner_cannot_enroll_someone_else_using_legacy_endpoint(): void
    {
        $this->enroll();
        $this->user->update(['role' => 'owner']);
        $this->postJson('/api/users/'.$this->user->id.'/face', [])->assertStatus(405);
    }

    public function test_previous_day_missing_exit_does_not_block_new_check_in(): void
    {
        $this->enroll();
        $c = $this->complete('in');
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertOk();
        $oldDate = now('Asia/Dhaka')->toDateString();
        $this->travel(1)->days();
        $this->signIn($this->user);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('today', null);
        $c = $this->complete('out');
        $this->postJson('/api/attendance', ['action' => 'out', 'challenge_id' => $c['id']])->assertStatus(409);
        $this->getJson('/api/attendance?start_date='.$oldDate)->assertOk()->assertJsonPath('data.0.status', 'missing_exit')->assertJsonPath('summary.missing_exits', 1);
        $c = $this->complete('in');
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertOk();
    }

    public function test_postgres_offset_is_serialized_as_utc_iso(): void
    {
        $row = FaceProof::attendance((object) ['check_in' => '2026-10-13 09:15:30+06', 'check_out' => '2026-10-13 18:15:30+06']);
        $this->assertSame('2026-10-13T03:15:30.000000Z', $row->check_in);
        $this->assertSame('2026-10-13T12:15:30.000000Z', $row->check_out);
    }

    public function test_unenrolled_user_cannot_access_any_workspace_features(): void
    {
        foreach (['dashboard', 'applications', 'attendance', 'teams', 'users', 'leave-types', 'calendar', 'audit'] as $path) {
            $this->getJson('/api/'.$path)->assertForbidden()->assertJsonPath('face_enrollment_required', true);
        }
        $this->postJson('/api/applications', [])->assertForbidden();
        $this->getJson('/api/me')->assertOk();
        $this->enroll();
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_owner_can_enroll_another_user_and_employee_cannot(): void
    {
        $this->enroll();
        $target = User::create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secure-password-123', 'role' => 'employee', 'active' => true]);
        $this->postJson('/api/face/challenges', ['purpose' => 'enroll', 'consent' => true, 'user_id' => $target->id])->assertForbidden();
        $this->user->update(['role' => 'owner']);
        $c = $this->postJson('/api/face/challenges', ['purpose' => 'enroll', 'consent' => true, 'user_id' => $target->id])->assertOk()->json();
        while (! $c['complete']) {
            $c = $this->step($c)->assertOk()->json();
        }
        $this->postJson('/api/face/enroll', ['challenge_id' => $c['id']])->assertOk()->assertJsonPath('user_id', $target->id);
        $this->assertDatabaseHas('face_profiles', ['user_id' => $target->id, 'enrolled_by' => $this->user->id, 'verification_version' => 2]);
        $this->signIn($target);
        $this->getJson('/api/me')->assertJsonPath('face_enrolled', true);
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_unenrolled_owner_must_enroll_self_before_enrolling_others(): void
    {
        $this->user->update(['role' => 'owner']);
        $target = User::create(['name' => 'Target', 'email' => 'target@example.com', 'password' => 'secure-password-123', 'role' => 'employee', 'active' => true]);
        $this->postJson('/api/face/challenges', ['purpose' => 'enroll', 'consent' => true, 'user_id' => $target->id])->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_one_tap_live_capture_completes_attendance_verification(): void
    {
        $this->enroll();
        $c = $this->start('in');
        $this->assertSame(1, $c['total']);
        $this->serviceBody = ['distance' => .1903, 'live_score' => .95, 'live' => true, 'frames_checked' => 4, 'model' => 'minivision-b6d5f04-ensemble-v1'];
        $this->step($c)->assertOk()->assertJsonPath('complete', true);
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertOk();
    }

    public function test_invalid_face_response_and_service_failure_do_not_record_attendance(): void
    {
        $this->enroll();
        $c = $this->start('in');
        $this->serviceBody = [];
        $this->step($c)->assertStatus(503);
        $this->serviceStatus = 503;
        $this->step($c)->assertStatus(503);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_cannot_skip_capture_or_reuse_old_passive_proof(): void
    {
        $this->enroll();
        $c = $this->start('in');
        $this->post('/api/face/challenges/'.$c['id'].'/step', ['nonce' => $c['nonce'], 'frames' => $this->frames()], ['Accept' => 'application/json'])->assertUnprocessable();
        DB::table('face_challenges')->where('id', $c['id'])->update(['completed_at' => now(), 'capture_hash' => str_repeat('a', 64), 'state' => Crypt::encryptString(json_encode(['passive_verified' => true, 'distance' => 0]))]);
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_identical_photo_cannot_be_reused_for_checkout(): void
    {
        $this->enroll();
        $c = $this->start('in');
        $frames = $this->frames();
        $photo = $frames[0];
        $c = $this->post('/api/face/challenges/'.$c['id'].'/capture', ['nonce' => $c['nonce'], 'photo' => $photo, 'frames' => $frames], ['Accept' => 'application/json'])->assertOk()->json();
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertOk();
        $c = $this->start('out');
        $this->post('/api/face/challenges/'.$c['id'].'/capture', ['nonce' => $c['nonce'], 'photo' => $photo, 'frames' => $frames], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseHas('attendances', ['user_id' => $this->user->id, 'check_out' => null]);
    }

    public function test_low_liveness_rejects_even_a_matching_face(): void
    {
        $this->enroll();
        $this->serviceBody = ['distance' => .1903, 'live_score' => .0012, 'live' => true, 'frames_checked' => 4, 'model' => 'minivision-b6d5f04-ensemble-v1'];
        $c = $this->start('in');
        $this->step($c)->assertUnprocessable();
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_missing_liveness_and_wrong_model_fail_closed(): void
    {
        $this->enroll();
        foreach ([['distance' => .1], ['distance' => .1, 'live_score' => .99, 'live' => true, 'frames_checked' => 4, 'model' => 'unknown']] as $body) {
            $this->serviceBody = $body;
            $this->step($this->start('in'))->assertStatus(503);
        }
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_duplicate_frames_and_unbound_photo_are_rejected_before_service(): void
    {
        $this->enroll();
        $frames = $this->frames();
        $c = $this->start('in');
        $this->step($c, array_fill(0, 4, $frames[0]))->assertUnprocessable();
        $this->post('/api/face/challenges/'.$c['id'].'/capture', ['nonce' => $c['nonce'], 'photo' => $this->frames()[0], 'frames' => $frames], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_old_matching_only_proof_cannot_record_attendance(): void
    {
        $this->enroll();
        $c = $this->complete('in');
        $row = DB::table('face_challenges')->where('id', $c['id'])->first();
        $state = json_decode(Crypt::decryptString($row->state), true);
        unset($state['liveness_verified']);
        DB::table('face_challenges')->where('id', $c['id'])->update(['state' => Crypt::encryptString(json_encode($state))]);
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => $c['id']])->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 0);
    }
}
