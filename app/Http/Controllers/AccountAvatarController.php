<?php

namespace App\Http\Controllers;

use App\Infrastructure\Accounts\ProfilePhoto;
use App\Infrastructure\Security\LockActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AccountAvatarController extends Controller
{
    public function show(Request $request, LockActor $actors)
    {
        $actor = $actors->current($request->user());
        $disk = Storage::disk('local');
        abort_unless($actor->avatar_path && $actor->avatar_sha256 && $disk->exists($actor->avatar_path), 404);
        $contents = $disk->get($actor->avatar_path);
        abort_unless(hash_equals($actor->avatar_sha256, hash('sha256', $contents)), 409);

        return response($contents, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="perfil.jpg"']);
    }

    public function store(Request $request, ProfilePhoto $photos)
    {
        $request->validate(['avatar' => 'required|file']);
        $photos->replace($request->user(), $request->file('avatar'));
        Auth::setUser($request->user()->fresh());

        return redirect()->route('account.edit')->with('status', 'Foto de perfil atualizada.');
    }

    public function destroy(Request $request, ProfilePhoto $photos)
    {
        $photos->remove($request->user());
        Auth::setUser($request->user()->fresh());

        return redirect()->route('account.edit')->with('status', 'Foto removida. Sua inicial voltou a ser exibida.');
    }
}
