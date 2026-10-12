<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrativeMutationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function mutations(): array
    {
        return [['catalogs.store'], ['users.store'], ['users.update']];
    }

    #[DataProvider('mutations')]
    public function test_session_revocation_after_http_middleware_still_blocks_admin_mutations(string $route): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $target = User::create(['name' => 'Usuário original', 'username' => 'REGULAR', 'password' => 'SenhaFicticia123']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'scope'), 'fund_id' => $fund, 'fund' => 'Fundo']);
        $target->scopes()->attach($scope);
        Route::getRoutes()->getByName($route)->middleware(RevokeActorAfterMiddleware::class);
        $this->actingAs($admin)->withSession(['auth_version' => 0]);
        $data = ['name' => 'Cadastro indevido', 'username' => 'NEW_USER', 'user_type' => 'Usuario', 'is_active' => true, 'scope_ids' => [$scope->id], 'password' => 'SenhaFicticia123', 'password_confirmation' => 'SenhaFicticia123'];
        if ($route === 'catalogs.store') {
            $this->post('/catalogs', ['kind' => 'department', 'name' => 'Secretaria indevida'])->assertForbidden();
            $this->assertDatabaseCount('contract_departments', 0);
        } elseif ($route === 'users.store') {
            $this->post('/users', $data)->assertForbidden();
            $this->assertDatabaseCount('users', 2);
        } else {
            unset($data['password'], $data['password_confirmation']);
            $this->put('/users/'.$target->id, $data)->assertForbidden();
            $this->assertSame('Usuário original', $target->fresh()->name);
        }
        $this->assertDatabaseCount('user_audits', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }
}

/** Test-only race injection, after the application's auth/session middleware. */
class RevokeActorAfterMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        User::whereKey($request->user()->id)->increment('session_version');

        return $next($request);
    }
}
