<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'Asia/Dhaka'));
    }

    private function person(string $name, string $joined = '2026-09-01'): User
    {
        $u = User::create(['name' => $name, 'email' => $name.'@example.com', 'password' => 'abcdef', 'role' => 'employee', 'active' => true, 'created_at' => $joined.' 00:00:00']);
        DB::table('users')->where('id', $u->id)->update(['created_at' => $joined.' 00:00:00']);
        DB::table('face_profiles')->insert(['user_id' => $u->id, 'enrolled_by' => $u->id, 'verification_version' => 2, 'embedding' => Crypt::encryptString('{"templates":[[]]}')]);

        return $u;
    }

    public function test_permissions_scope_and_period_are_independent(): void
    {
        $viewer = $this->person('viewer');
        $member = $this->person('member');
        $this->person('outside');
        $team = DB::table('teams')->insertGetId(['name' => 'One']);
        foreach ([$viewer, $member] as $u) {
            DB::table('team_user')->insert(['team_id' => $team, 'user_id' => $u->id, 'team_role' => 'employee']);
        }
        $this->passportSignIn($viewer)->getJson('/api/analytics')->assertForbidden();
        $viewer->givePermissionTo('analytics.view_daily');
        $this->getJson('/api/analytics')->assertOk()->assertJsonPath('scope', 'self')->assertJsonPath('daily.total_employees', 1)->assertJsonPath('monthly', null);
        $viewer->givePermissionTo('analytics.view_team');
        $this->getJson('/api/analytics')->assertOk()->assertJsonPath('daily.total_employees', 2);
        $viewer->givePermissionTo('analytics.view_all', 'analytics.view_monthly');
        $this->getJson('/api/analytics')->assertOk()->assertJsonPath('daily.total_employees', 3)->assertJsonPath('monthly.total_employees', 3);
        $viewer->revokePermissionTo('analytics.view_daily');
        $this->getJson('/api/analytics')->assertOk()->assertJsonPath('daily', null);
        $this->getJson('/api/analytics?month=2026-11')->assertUnprocessable();
        $this->getJson('/api/analytics?date=2026-10-16')->assertUnprocessable();
    }

    public function test_counts_deduplicate_home_office_and_respect_join_dates_calendar_and_absence(): void
    {
        $viewer = $this->person('viewer');
        $present = $this->person('present');
        $leave = $this->person('leave');
        $remote = $this->person('remote', '2026-10-15');
        $future = $this->person('future', '2026-10-20');
        $viewer->givePermissionTo('analytics.view_daily', 'analytics.view_monthly', 'analytics.view_all');
        foreach (['One', 'Two'] as $name) {
            $team = DB::table('teams')->insertGetId(['name' => $name]);
            DB::table('team_user')->insert(['team_id' => $team, 'user_id' => $remote->id, 'team_role' => 'employee']);
            DB::table('team_home_office')->insert(['team_id' => $team, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'reason' => 'Remote work', 'created_by' => $viewer->id]);
        }
        DB::table('attendances')->insert(['user_id' => $present->id, 'work_date' => '2026-10-15', 'check_in' => '2026-10-15 03:00:00', 'check_out' => '2026-10-15 12:00:00', 'mode' => 'office', 'in_photo' => 'a.jpg', 'in_distance' => .1]);
        DB::table('applications')->insert(['user_id' => $leave->id, 'kind' => 'leave', 'start_date' => '2026-10-15', 'end_date' => '2026-10-15', 'reason' => 'Approved leave', 'status' => 'approved']);
        $this->passportSignIn($viewer)->getJson('/api/analytics')->assertOk()->assertJsonPath('daily.total_employees', 4)->assertJsonPath('daily.new_joiners', 1)->assertJsonPath('daily.present', 1)->assertJsonPath('daily.on_leave', 1)->assertJsonPath('daily.home_office', 1)->assertJsonPath('daily.absent', 2)->assertJsonPath('monthly.working_minutes', 540)->assertJsonPath('monthly.new_joiners', 1);
        DB::table('calendar_days')->insert(['date' => '2026-10-14', 'name' => 'Holiday', 'is_working' => false]);
        $this->getJson('/api/analytics?date=2026-10-14')->assertOk()->assertJsonPath('daily.total_employees', 3)->assertJsonPath('daily.absent', 0);
    }
}
