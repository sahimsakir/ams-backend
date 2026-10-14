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
        $user = User::with(['assignedRole.permissions', 'directPermissions'])->find($token->user_id);
        abort_unless($user && $user->active, 401, 'Account unavailable.');
        $r->setUserResolver(fn () => $user);

        $allowed = $r->is('api/me', 'api/logout', 'api/face/challenges', 'api/face/challenges/*/step', 'api/face/challenges/*/capture', 'api/face/enroll');
        if (! $allowed && ! DB::table('face_profiles')->where('user_id', $user->id)->where('verification_version', '>=', 2)->exists()) {
            return response()->json(['message' => 'Enroll your face to unlock your workspace.', 'face_enrollment_required' => true], 403);
        }

        return $next($r);
    }
}
