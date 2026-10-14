<?php

namespace App\Http\Middleware;

use App\Infrastructure\Security\SelectedFund;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $fundId = $request->session()->get('fund_id');
        $fund = $user && $fundId ? app(SelectedFund::class)->current($user, (int) $fundId) : null;
        if ($user && (! $user->is_active || (int) $request->session()->get('auth_version', 0) !== $user->session_version || ($fundId !== null && ! $fund) || (Auth::viaRemember() && ! $fund))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Seu acesso foi atualizado. Entre novamente.'], 401);
            }

            return redirect()->route('login')->withErrors(['username' => 'Seu acesso foi atualizado. Entre novamente.']);
        }

        $request->attributes->set('selected_fund', $fund ? ['id' => $fund->id, 'name' => $fund->name] : null);
        $request->attributes->set('available_funds', $user ? app(SelectedFund::class)->options($user) : collect());

        return $next($request);
    }
}
