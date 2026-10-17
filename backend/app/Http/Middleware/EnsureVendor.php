<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureVendor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('vendor.login');
        }
        abort_unless($request->user()->is_vendor && ! $request->user()->is_admin, 403);
        $vendor = DB::table('vendors')->where('user_id', $request->user()->id)->first();
        abort_unless($vendor, 403);
        $request->attributes->set('vendor', $vendor);

        return $next($request);
    }
}
