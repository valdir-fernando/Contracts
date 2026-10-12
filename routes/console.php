<?php

use App\Infrastructure\Operations\ProcessHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment('Vamos construir algo útil.');
})->purpose('Exibir uma mensagem de inspiração');

Schedule::command('contracts:prune-exports')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => app(ProcessHeartbeat::class)->beat('scheduler'))->everyMinute()->name('contracts:scheduler-heartbeat');
Schedule::command('contracts:sync-legacy')->hourly()->withoutOverlapping()->when(fn () => config('legacy.sync_enabled'));
