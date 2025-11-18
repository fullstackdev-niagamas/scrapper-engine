<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IsLogin
{
    // Durasi sesi maksimum (detik) — 48 jam
    private const MAX_AGE_SECONDS = 48 * 60 * 60;

    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return $this->forceLogout($request);
        }

        $loginAt = (int) ($request->session()->get('login_at') ?? 0);

        if ($loginAt === 0 || (time() - $loginAt) > self::MAX_AGE_SECONDS) {
            return $this->forceLogout($request, 'Sesi berakhir. Silakan login kembali.');
        }

        return $next($request);
    }

    private function forceLogout(Request $request, string $message = 'Silakan login.')
    {
        try {
            Auth::logout();
        } catch (\Throwable $e) {
            // ignore
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login')->withErrors($message);
    }
}