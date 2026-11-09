<?php

namespace App\Infrastructure\Atas;

final class AtaAmendmentSourcePolicy
{
    public function isUnsigned(string $path): bool
    {
        $path = $this->normalize($path);
        foreach (config('atas.unsigned_amendment_directories', []) as $directory) {
            $absolute = str_starts_with($directory, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $directory)
                ? $directory : base_path($directory);
            $root = $this->normalize($absolute);
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            $resolved = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) ? $path : base_path($path);
        }

        return mb_strtolower(rtrim(str_replace('\\', '/', $resolved), '/'));
    }
}
