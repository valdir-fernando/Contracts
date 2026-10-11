<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::defaultView('components.pagination');

        if (app()->environment('production')) {
            URL::forceScheme('https');
            config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        }
        Livewire::addPersistentMiddleware([EnsureActiveUser::class]);
    }
}
