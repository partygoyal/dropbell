<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $record = $token ? DB::table('customer_tokens')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first() : null;
        $user = $record ? User::find($record->user_id) : null;
        if (! $user || $user->is_admin || $user->is_vendor) {
            return response()->json(['message' => 'Please sign in to continue.'], 401);
        }
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('customer_token_id', $record->id);

        return $next($request);
    }
}
