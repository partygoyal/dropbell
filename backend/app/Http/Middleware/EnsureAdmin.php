<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()) return redirect()->route('admin.login');
        abort_unless($request->user()->is_admin, 403);
        return $next($request);
    }
}
