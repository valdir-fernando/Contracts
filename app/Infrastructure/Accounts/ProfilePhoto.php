<?php

namespace App\Infrastructure\Accounts;

use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProfilePhoto
{
    public function __construct(private LockActor $actors) {}

    public function replace(User $expected, UploadedFile $file): void
    {
        $this->actors->current($expected);
        Validator::make(['avatar' => $file], ['avatar' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:min_width=32,min_height=32,max_width=3000,max_height=3000'])->validate();
        $image = @imagecreatefromstring($file->getContent());
        if ($image === false) {
            throw ValidationException::withMessages(['avatar' => 'Escolha uma foto JPEG, PNG ou WebP válida.']);
        }
        $thumbnail = imagecreatetruecolor(256, 256);
        $stream = fopen('php://temp', 'w+');
        try {
            imagefill($thumbnail, 0, 0, imagecolorallocate($thumbnail, 255, 255, 255));
            $side = min(imagesx($image), imagesy($image));
            imagecopyresampled($thumbnail, $image, 0, 0, intdiv(imagesx($image) - $side, 2), intdiv(imagesy($image) - $side, 2), 256, 256, $side, $side);
            if (! imagejpeg($thumbnail, $stream, 90)) {
                throw ValidationException::withMessages(['avatar' => 'Não foi possível preparar a foto.']);
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
            imagedestroy($image);
            imagedestroy($thumbnail);
        }
        $disk = Storage::disk('local');
        $path = 'profile-photos/'.Str::uuid().'.jpg';
        try {
            $disk->put($path, $contents, 'private');
            $oldPath = DB::transaction(function () use ($expected, $path, $contents) {
                $actor = $this->actors->lock($expected);
                $oldPath = $actor->avatar_path;
                $actor->avatar_path = $path;
                $actor->avatar_sha256 = hash('sha256', $contents);
                $actor->save();
                ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'profile_photo_updated', 'metadata' => [], 'created_at' => now()]);

                return $oldPath;
            }, 3);
        } catch (\Throwable $error) {
            $disk->delete($path);
            throw $error;
        }
        if ($oldPath) {
            $disk->delete($oldPath);
        }
    }

    public function remove(User $expected): void
    {
        $oldPath = DB::transaction(function () use ($expected) {
            $actor = $this->actors->lock($expected);
            $oldPath = $actor->avatar_path;
            if ($oldPath === null) {
                return null;
            }
            $actor->avatar_path = null;
            $actor->avatar_sha256 = null;
            $actor->save();
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'profile_photo_removed', 'metadata' => [], 'created_at' => now()]);

            return $oldPath;
        }, 3);
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }
    }
}
