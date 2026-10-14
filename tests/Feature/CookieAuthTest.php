<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CookieAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->user = User::create(['name' => 'Member', 'email' => 'cookie@example.com', 'password' => 'secure-password-123', 'role' => 'employee', 'active' => true]);
        DB::table('face_profiles')->insert(['user_id' => $this->user->id, 'enrolled_by' => $this->user->id, 'verification_version' => 2, 'embedding' => Crypt::encryptString('{}')]);
        $this->passportSignIn($this->user);
    }

    public function test_bearer_token_is_rejected_without_cookie(): void
    {
        $jwt = $this->user->createToken('Header should not work')->accessToken;
        $this->withCookie('ams_auth', '')->withToken($jwt)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_csrf_is_required_for_cookie_login_and_logout(): void
    {
        config(['ams.enforce_csrf_in_tests' => true]);
        $this->postJson('/api/login', ['email' => $this->user->email, 'password' => 'secure-password-123'])->assertStatus(419);
        $csrf = $this->getJson('/api/csrf-cookie')->assertOk();
        foreach ($csrf->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        $login = $this->withHeader('X-XSRF-TOKEN', $csrf->getCookie('XSRF-TOKEN', false)->getValue())->postJson('/api/login', ['email' => $this->user->email, 'password' => 'secure-password-123'])->assertOk();
        $this->assertArrayNotHasKey('token', $login->json());
        $auth = $login->getCookie('ams_auth', false);
        $this->assertTrue($auth->isHttpOnly());
        $this->assertSame('lax', $auth->getSameSite());
        $this->assertSame('/api', $auth->getPath());
        foreach ($login->headers->getCookies() as $cookie) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
        $this->getJson('/api/me')->assertOk();
        $this->withHeader('X-XSRF-TOKEN', '')->postJson('/api/logout')->assertStatus(419);
        $this->withHeader('X-XSRF-TOKEN', $login->getCookie('XSRF-TOKEN', false)->getValue())->postJson('/api/logout')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_secure_cookie_setting_and_password_revocation(): void
    {
        config(['session.secure' => true]);
        $login = $this->postJson('/api/login', ['email' => $this->user->email, 'password' => 'secure-password-123'])->assertOk();
        $this->assertTrue($login->getCookie('ams_auth', false)->isSecure());
        $this->putJson('/api/password', ['current_password' => 'secure-password-123', 'password' => 'new-secure-password-123', 'password_confirmation' => 'new-secure-password-123'])->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $this->user->id)->where('revoked', false)->count());
    }

    public function test_spatie_relationships_are_used(): void
    {
        $this->assertInstanceOf(Role::class, $this->user->roles->first());
        $this->assertTrue($this->user->hasPermissionTo('attendance.view', 'api'));
        $this->assertTrue($this->user->can('attendance.view'));
    }

    public function test_existing_custom_roles_and_direct_grants_migrate_without_name_collisions(): void
    {
        $permission = Permission::where('slug', 'calendar.manage')->first();
        $this->user->givePermissionTo($permission);
        $migration = require database_path('migrations/2026_10_14_000008_migrate_spatie_grants.php');
        $migration->down();
        $role = DB::table('roles')->insertGetId(['name' => 'manager', 'slug' => 'legacy_custom', 'system' => false]);
        DB::table('role_permission')->insert(['role_id' => $role, 'permission_id' => $permission->id]);
        DB::table('users')->where('id', $this->user->id)->update(['role_id' => $role, 'role' => 'legacy_custom']);
        $migration->up();
        $fresh = User::find($this->user->id);
        $this->assertTrue($fresh->hasRole('legacy_custom', 'api'));
        $this->assertTrue($fresh->hasDirectPermission('calendar.manage', 'api'));
        $this->assertTrue($fresh->hasPermissionTo('calendar.manage', 'api'));
        $this->assertDatabaseHas('roles', ['id' => $role, 'name' => 'legacy_custom', 'display_name' => 'manager']);
    }
}
