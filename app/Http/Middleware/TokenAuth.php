<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TokenAuth
{
    public function handle(Request $r, Closure $next)
    {
        $token = $r->cookie('ams_auth');
        abort_unless(is_string($token) && $token !== '', 401, 'Session expired. Please sign in.');
        // Client bearer headers are never accepted; only the decrypted HttpOnly cookie.
        $r->headers->set('Authorization', 'Bearer '.$token);
        Auth::forgetGuards();
        $guard = Auth::guard('api');
        $guard->setRequest($r);
        $user = $guard->user();
        abort_unless($user && $user->active, 401, 'Account unavailable.');
        $user->load(['roles.permissions', 'assignedRole', 'directPermissions']);
        $r->setUserResolver(fn () => $user);

        $allowed = $r->is('api/me', 'api/logout', 'api/face/challenges', 'api/face/challenges/*/step', 'api/face/challenges/*/capture', 'api/face/enroll');
        if (! $allowed && ! DB::table('face_profiles')->where('user_id', $user->id)->where('verification_version', '>=', 2)->exists()) {
            return response()->json(['message' => 'Enroll your face to unlock your workspace.', 'face_enrollment_required' => true], 403);
        }

        return $next($r);
    }
}
