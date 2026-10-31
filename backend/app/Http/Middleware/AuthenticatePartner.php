<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePartner
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $record = $token ? DB::table('partner_tokens')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first() : null;
        $partner = $record ? DB::table('delivery_partners')->where('id', $record->delivery_partner_id)->where('phone', $record->phone)->where('active', true)->first() : null;
        if (! $partner || ! DB::table('delivery_partner_kycs')->where('delivery_partner_id', $partner->id)->where('status', 'approved')->exists()) {
            return response()->json(['message' => 'Sign in with an active, KYC-approved rider account.'], 401);
        }
        $request->attributes->set('partner', $partner);
        $request->attributes->set('partner_token_id', $record->id);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
