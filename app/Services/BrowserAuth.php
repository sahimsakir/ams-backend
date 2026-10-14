<?php

namespace App\Services;

use Illuminate\Support\Facades\Cookie;
use Laravel\Passport\Token;

class BrowserAuth
{
    public static function cookie(string $token)
    {
        return Cookie::make('ams_auth', $token, 480, '/api', null, (bool) config('session.secure'), true, false, 'lax');
    }

    public static function forget()
    {
        return Cookie::forget('ams_auth', '/api');
    }

    public static function revokeUser(int $id): void
    {
        Token::where('user_id', $id)->update(['revoked' => true]);
    }
}
