<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function edit()
    {
        return view('account.edit');
    }

    public function update(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($request->user()->id)],
            'current_password' => 'required|current_password',
            'password' => ['nullable', 'confirmed', Password::min(12)->letters()->numbers(), 'max:1024'],
            'user_type' => 'prohibited', 'scope_ids' => 'prohibited', 'is_active' => 'prohibited']);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'A senha atual foi alterada. Entre novamente.']);
            }
            abort_unless($user->is_active && ! $user->trashed(), 403);
            $user->name = $data['name'];
            if ($user->email !== ($data['email'] ?? null)) {
                $user->email_verified_at = null;
            }
            $user->email = $data['email'] ?? null;
            if (! empty($data['password'])) {
                $user->password = $data['password'];
                $user->session_version++;
                $user->remember_token = null;
            }
            $user->save();
            ActivityEvent::create(['actor_id' => $user->id, 'action' => empty($data['password']) ? 'account_updated' : 'own_password_changed',
                'metadata' => [], 'created_at' => now()]);
            $request->session()->put('auth_version', $user->session_version);
            Auth::setUser($user);
        });
        $request->session()->regenerate();

        return redirect()->route('account.edit')->with('status', 'Conta atualizada.');
    }
}
