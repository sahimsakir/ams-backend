<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $r, Closure $next, string $permission)
    {
        abort_unless(collect(explode('|', $permission))->contains(fn ($key) => $r->user()->hasPermission($key)), 403, 'You do not have permission to perform this action.');

        return $next($r);
    }
}
