<?php

namespace App\Http\Middleware;

use App\Domain\Auth\ClerkTokenVerifier;
use App\Domain\Auth\ClerkUserResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bila request membawa "Authorization: Bearer <session token Clerk>", pelanggan dijadikan
 * pengguna untuk request ini saja (tanpa menulis sesi). Endpoint admin diabaikan karena
 * admin tetap login lewat sesi Sanctum. Token tidak sah dibiarkan: endpoint yang butuh
 * login akan membalas 401 seperti biasa.
 */
class AuthenticateClerkToken
{
    public function __construct(
        private readonly ClerkTokenVerifier $verifier,
        private readonly ClerkUserResolver $users,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token && ! $request->is('api/v1/admin', 'api/v1/admin/*') && $this->verifier->configured()) {
            try {
                $clerkUserId = $this->verifier->verify($token);
                $user = $clerkUserId ? $this->users->resolve($clerkUserId) : null;
            } catch (Throwable $error) {
                report($error);
                $user = null;
            }
            if ($user) {
                Auth::guard('web')->setUser($user);
                Auth::shouldUse('web');
            }
        }

        return $next($request);
    }
}
