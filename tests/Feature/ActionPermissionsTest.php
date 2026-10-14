<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActionPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    }

    private function user(string $role = 'employee'): User
    {
        $u = User::create(['name' => $role, 'email' => uniqid().'@example.com', 'role' => $role, 'password' => 'secure-password-123', 'active' => true]);
        DB::table('face_profiles')->insert(['user_id' => $u->id, 'enrolled_by' => $u->id, 'verification_version' => 2, 'embedding' => Crypt::encryptString('{"templates":[[0.1,0.2]]}')]);

        return $u;
    }

    private function grant(User $u, array $keys): void
    {
        $u->givePermissionTo(Permission::whereIn('name', $keys)->get());
    }

    public function test_team_lead_sees_union_of_assigned_teams_and_not_unrelated_users(): void
    {
        $lead = $this->user('team_lead');
        $one = $this->user();
        $two = $this->user();
        $outside = $this->user();
        foreach ([[$lead, $one], [$lead, $two], [$outside]] as $i => $members) {
            $team = DB::table('teams')->insertGetId(['name' => 'Team '.$i]);
            foreach ($members as $u) {
                DB::table('team_user')->insert(['team_id' => $team, 'user_id' => $u->id, 'team_role' => 'employee']);
            }
        }
        $day = now('Asia/Dhaka')->toDateString();
        foreach ([$one, $two, $outside] as $u) {
            DB::table('attendances')->insert(['user_id' => $u->id, 'work_date' => $day, 'check_in' => now(), 'in_photo' => 'private.jpg', 'in_distance' => .1, 'mode' => 'office']);
            DB::table('applications')->insert(['user_id' => $u->id, 'kind' => 'home_office', 'start_date' => $day, 'end_date' => $day, 'reason' => 'Remote work']);
        }
        Storage::fake('local');
        Storage::disk('local')->put('private.jpg', 'photo');
        $this->passportSignIn($lead)->getJson('/api/attendance')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.can_view_photo', true);
        $memberRecord = DB::table('attendances')->where('user_id', $one->id)->value('id');
        $outsideRecord = DB::table('attendances')->where('user_id', $outside->id)->value('id');
        $this->get('/api/attendance/'.$memberRecord.'/photo/in')->assertOk();
        $this->get('/api/attendance/'.$outsideRecord.'/photo/in')->assertNotFound();
        $lead->assignedRole->revokePermissionTo('attendance.photo_view_team');
        $this->get('/api/attendance/'.$memberRecord.'/photo/in')->assertNotFound();
        $this->grant($lead, ['attendance.photo_view_team']);
        $this->get('/api/attendance/'.$memberRecord.'/photo/in')->assertOk();
        $this->getJson('/api/attendance?user_id='.$outside->id)->assertForbidden();
        $this->getJson('/api/attendance/people')->assertOk()->assertJsonCount(3);
        $this->getJson('/api/applications')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/users/'.$one->id.'/profile')->assertForbidden();
        $this->grant($lead, ['profile.view_team']);
        $this->getJson('/api/users/'.$one->id.'/profile')->assertOk()->assertJsonPath('user.id', $one->id);
        $this->getJson('/api/users/'.$outside->id.'/profile')->assertForbidden();
    }

    public function test_create_update_delete_actions_are_independent(): void
    {
        $u = $this->user();
        $this->grant($u, ['leave_types.create']);
        $this->passportSignIn($u);
        $this->postJson('/api/leave-types', ['name' => 'Special', 'annual_limit' => 3])->assertOk();
        $id = DB::table('leave_types')->where('name', 'Special')->value('id');
        $this->putJson('/api/leave-types/'.$id, ['name' => 'Changed', 'annual_limit' => 5])->assertForbidden();
        $this->deleteJson('/api/leave-types/'.$id)->assertForbidden();
        $this->grant($u, ['leave_types.update']);
        $this->putJson('/api/leave-types/'.$id, ['name' => 'Changed', 'annual_limit' => 5])->assertOk();
        $this->deleteJson('/api/leave-types/'.$id)->assertForbidden();
    }

    public function test_check_in_and_check_out_are_independent(): void
    {
        $role = Role::create(['name' => 'Checkout only', 'slug' => 'checkout_only']);
        $role->givePermissionTo(Permission::where('name', 'attendance.check_out')->first());
        $u = $this->user();
        $u->update(['role_id' => $role->id]);
        $this->passportSignIn($u)->postJson('/api/face/challenges', ['purpose' => 'in', 'consent' => true])->assertForbidden();
        $this->postJson('/api/face/challenges', ['purpose' => 'out', 'consent' => true])->assertOk();
        $this->postJson('/api/attendance', ['action' => 'in', 'challenge_id' => 'bad'])->assertForbidden();
    }

    public function test_profile_and_password_permissions_are_independent(): void
    {
        $role = Role::create(['name' => 'Profile viewer', 'slug' => 'profile_viewer']);
        $role->givePermissionTo(Permission::where('name', 'profile.view_self')->first());
        $u = $this->user();
        $u->update(['role_id' => $role->id]);
        $this->passportSignIn($u);
        $this->getJson('/api/profile')->assertOk();
        $this->putJson('/api/profile', ['name' => 'Updated'])->assertForbidden();
        $this->putJson('/api/password', [])->assertForbidden();
        $this->grant($u, ['profile.update_self']);
        $this->putJson('/api/profile', ['name' => 'Updated', 'phone' => '123'])->assertOk();
    }

    public function test_delegated_access_admin_cannot_grant_more_than_they_hold(): void
    {
        $u = $this->user();
        $target = $this->user();
        $owner = $this->user('owner');
        $this->grant($u, ['users.assign_access', 'roles.create', 'roles.view', 'permissions.view']);
        $this->passportSignIn($u);
        $power = Permission::where('name', 'attendance.view_all')->value('id');
        $this->postJson('/api/roles', ['name' => 'Elevated', 'slug' => 'elevated', 'permission_ids' => [$power]])->assertForbidden();
        $this->putJson('/api/users/'.$target->id.'/access', ['role_id' => $owner->role_id, 'permission_ids' => []])->assertForbidden();
        $this->putJson('/api/users/'.$target->id.'/access', ['role_id' => $target->role_id, 'permission_ids' => [$power]])->assertForbidden();
    }
}
