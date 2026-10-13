<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TokenAuth
{
    public function handle(Request $r, Closure $next)
    {
        $token = DB::table('access_tokens')->where('token_hash', hash('sha256', $r->bearerToken() ?? ''))->where('expires_at', '>', now())->first();
        abort_unless($token, 401, 'Session expired. Please sign in.');
        $user = User::find($token->user_id);
        abort_unless($user && $user->active, 401, 'Account unavailable.');
        $r->setUserResolver(fn () => $user);

        return $next($r);
    }
}
