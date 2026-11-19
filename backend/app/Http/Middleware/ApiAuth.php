<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $plain = $request->bearerToken();
        if (!$plain) {
            return response()->json(['message' => 'Silakan login terlebih dahulu.'], 401);
        }

        $token = ApiToken::with('user')
            ->where('token_hash', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->first();

        if (!$token || !$token->user || !$token->user->is_active) {
            return response()->json(['message' => 'Sesi tidak valid atau sudah berakhir.'], 401);
        }

        $user = $token->user;
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
