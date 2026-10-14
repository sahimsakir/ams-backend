<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        foreach (['owner', 'employee'] as $slug) {
            $u = User::create(['name' => $slug, 'email' => $slug.'@example.com', 'role' => $slug, 'password' => 'secure-password-123', 'active' => true]);
            DB::table('face_profiles')->insert(['user_id' => $u->id, 'enrolled_by' => $u->id, 'verification_version' => 2, 'embedding' => Crypt::encryptString('{}')]);
            if ($slug === 'owner') {
                $this->owner = $u;
            } else {
                $this->member = $u;
            }
        }
    }

    private function asUser(User $u): static
    {
        return $this->passportSignIn($u);
    }

    public function test_initial_roles_and_relations_are_seeded(): void
    {
        $this->assertSame(['Owner', 'Management', 'Project Manager', 'Team Lead', 'Members'], Role::orderBy('id')->pluck('display_name')->all());
        $this->assertSame(Role::where('slug', 'employee')->value('id'), $this->member->role_id);
        $this->asUser($this->member)->getJson('/api/me')->assertJsonFragment(['role_name' => 'Members']);
    }

    public function test_role_grants_direct_grants_and_revocation_apply_to_existing_token(): void
    {
        $view = Permission::where('slug', 'users.view')->first();
        $calendar = Permission::where('slug', 'calendar.create')->first();
        $this->asUser($this->owner);
        $role = $this->postJson('/api/roles', ['name' => 'People Reader', 'slug' => 'people_reader', 'permission_ids' => [$view->id]])->assertCreated()->json('id');
        $this->putJson('/api/users/'.$this->member->id.'/access', ['role_id' => $role, 'permission_ids' => [$calendar->id]])->assertOk();
        $this->asUser($this->member)->getJson('/api/users')->assertOk();
        $this->postJson('/api/calendar', ['date' => '2026-10-22', 'name' => 'Holiday', 'is_working' => false])->assertOk();
        $this->postJson('/api/users', [])->assertForbidden();
        DB::table('role_permission')->where('role_id', $role)->delete();
        DB::table('model_has_permissions')->where('model_id', $this->member->id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/calendar', [])->assertForbidden();
    }

    public function test_owner_creates_updates_deletes_custom_roles_and_permissions(): void
    {
        $this->asUser($this->owner);
        $pid = $this->postJson('/api/permissions', ['name' => 'Export report', 'slug' => 'reports.export'])->assertCreated()->json('id');
        $rid = $this->postJson('/api/roles', ['name' => 'Exporter', 'slug' => 'exporter', 'permission_ids' => [$pid]])->assertCreated()->json('id');
        $this->putJson('/api/roles/'.$rid, ['name' => 'Report Exporter', 'slug' => 'report_exporter', 'permission_ids' => [$pid]])->assertOk();
        $this->putJson('/api/permissions/'.$pid, ['name' => 'Report export', 'slug' => 'reports.export_csv'])->assertOk();
        $this->putJson('/api/users/'.$this->member->id.'/access', ['role_id' => $rid, 'permission_ids' => [$pid]])->assertOk();
        $this->deleteJson('/api/roles/'.$rid)->assertUnprocessable();
        $this->deleteJson('/api/permissions/'.$pid)->assertOk();
        $this->assertDatabaseMissing('role_permission', ['permission_id' => $pid]);
        $this->assertDatabaseMissing('model_has_permissions', ['permission_id' => $pid]);
        $this->putJson('/api/users/'.$this->member->id.'/access', ['role_id' => Role::where('slug', 'employee')->value('id'), 'permission_ids' => []])->assertOk();
        $this->deleteJson('/api/roles/'.$rid)->assertOk();
    }

    public function test_members_cannot_administer_access_or_escalate_via_user_fields(): void
    {
        $this->asUser($this->member)->postJson('/api/roles', [])->assertForbidden();
        $this->getJson('/api/permissions')->assertForbidden();
        $this->putJson('/api/users/'.$this->member->id.'/access', ['role_id' => $this->owner->role_id, 'permission_ids' => []])->assertForbidden();
        $this->member->directPermissions()->attach(Permission::where('slug', 'users.update')->value('id'));
        $this->putJson('/api/users/'.$this->member->id, ['name' => 'Escalate', 'email' => $this->member->email, 'role' => 'owner', 'active' => true])->assertForbidden();
        $this->postJson('/api/permissions', [])->assertForbidden();
    }

    public function test_owner_and_builtin_keys_are_protected(): void
    {
        $this->asUser($this->owner)->deleteJson('/api/roles/'.$this->owner->role_id)->assertUnprocessable();
        $this->putJson('/api/users/'.$this->owner->id.'/access', ['role_id' => $this->member->role_id, 'permission_ids' => []])->assertUnprocessable();
        $p = Permission::where('slug', 'attendance.view')->first();
        $this->deleteJson('/api/permissions/'.$p->id)->assertUnprocessable();
        $this->putJson('/api/permissions/'.$p->id, ['name' => 'Changed', 'slug' => 'replacement'])->assertUnprocessable();
    }

    public function test_attendance_challenge_requires_record_permission(): void
    {
        $role = Role::create(['name' => 'Read Only', 'slug' => 'readonly']);
        $this->member->update(['role_id' => $role->id]);
        $this->asUser($this->member)->postJson('/api/face/challenges', ['purpose' => 'in', 'consent' => true])->assertForbidden();
        $this->postJson('/api/attendance', ['action' => 'in'])->assertForbidden();
    }
}
