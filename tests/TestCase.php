<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Passport\ClientRepository;

abstract class TestCase extends BaseTestCase
{
    protected function passportSignIn(User $u): static
    {
        $dir = storage_path('app/private/passport');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (! file_exists($dir.'/oauth-private.key')) {
            $this->artisan('passport:keys', ['--length' => 2048])->assertSuccessful();
        }
        $repo = app(ClientRepository::class);
        try {
            $repo->personalAccessClient('users');
        } catch (\RuntimeException $e) {
            $repo->createPersonalAccessGrantClient('Test Browser', 'users');
        }

        return $this->withCredentials()->withCookie('ams_auth', $u->createToken('Test browser')->accessToken);
    }
}
