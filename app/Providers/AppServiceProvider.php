<?php

namespace App\Providers;

use App\Application\Contracts\Ports\ContractAccess;
use App\Application\Contracts\Ports\ContractCatalog;
use App\Application\Contracts\Ports\ContractInputValidator;
use App\Application\Contracts\Ports\ContractStore;
use App\Application\Ports\TransactionManager;
use App\Http\Middleware\EnsureActiveUser;
use App\Infrastructure\Contracts\EloquentContractAccess;
use App\Infrastructure\Contracts\EloquentContractCatalog;
use App\Infrastructure\Contracts\EloquentContractStore;
use App\Infrastructure\Contracts\LaravelContractInputValidator;
use App\Infrastructure\Operations\ProcessHeartbeat;
use App\Infrastructure\Persistence\LaravelTransactionManager;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TransactionManager::class, LaravelTransactionManager::class);
        $this->app->bind(ContractAccess::class, EloquentContractAccess::class);
        $this->app->bind(ContractInputValidator::class, LaravelContractInputValidator::class);
        $this->app->bind(ContractCatalog::class, EloquentContractCatalog::class);
        $this->app->bind(ContractStore::class, EloquentContractStore::class);
    }

    public function boot(ProcessHeartbeat $heartbeat): void
    {
        Paginator::defaultView('components.pagination');

        if (app()->environment('production')) {
            URL::forceScheme('https');
            config(['session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax']);
        }
        Livewire::addPersistentMiddleware([EnsureActiveUser::class]);
        Queue::looping(fn () => $heartbeat->beat('worker'));
    }
}
