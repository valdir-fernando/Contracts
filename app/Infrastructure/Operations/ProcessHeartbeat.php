<?php

namespace App\Infrastructure\Operations;

use Illuminate\Support\Facades\Cache;

final class ProcessHeartbeat
{
    public function beat(string $process): void
    {
        Cache::put('contracts:heartbeat:'.$process, now()->timestamp, 1800);
    }

    public function healthy(string $process): bool
    {
        $timestamp = Cache::get('contracts:heartbeat:'.$process);
        // A worker may spend 900s rendering; the scheduler should tick every minute.
        $maxAge = $process === 'worker' ? 1020 : 150;

        return is_int($timestamp) && $timestamp <= now()->timestamp && now()->timestamp - $timestamp <= $maxAge;
    }
}
