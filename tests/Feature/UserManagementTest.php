<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\User;
use App\Models\UserAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(string $username, bool $admin = false): User
    {
        return User::create(['name' => $username, 'username' => $username, 'password' => 'SenhaSegura!123', 'user_type' => $admin ? 'Administrador' : 'Usuario']);
    }

    private function scope(string $fund = 'Fundo A', ?string $department = null): AccessScope
    {
        return AccessScope::create(['scope_key' => hash('sha256', $fund.'|'.$department), 'fund' => $fund, 'department' => $department]);
    }

    private function data(User $user, array $extra = []): array
    {
        return array_replace(['name' => $user->name, 'username' => $user->username, 'user_type' => $user->user_type,
            'is_active' => 1, 'scope_ids' => $user->scopes()->pluck('access_scopes.id')->all()], $extra);
    }

    public function test_admin_can_create_filter_and_render_users_without_exposing_passwords(): void
    {
        $admin = $this->user('ADMIN', true);
        $scope = $this->scope();
        $this->actingAs($admin)->get('/users/create')->assertOk()->assertSee('Novo usuário');
        $this->post('/users', ['name' => 'Maria', 'username' => ' maria ', 'user_type' => 'Usuario', 'is_active' => 1,
            'scope_ids' => [$scope->id], 'password' => 'NovaSenha!12345', 'password_confirmation' => 'NovaSenha!12345'])->assertRedirect('/users');
        $user = User::where('username', 'MARIA')->firstOrFail();
        $this->assertTrue(Hash::check('NovaSenha!12345', $user->password));
        $this->assertTrue($user->canAccessScope($scope->id));
        $this->get('/users?q=Maria&role=Usuario&status=active&scope='.$scope->id)->assertOk()->assertSee('MARIA')->assertDontSee('ADMIN</small>', false)->assertDontSee($user->password);
        $this->get('/users/'.$user->id.'/edit')->assertOk()->assertSee('Cadastro criado')->assertDontSee($user->password)->assertDontSee('NovaSenha!12345');
        $audit = UserAudit::firstOrFail();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertArrayNotHasKey('password', $audit->after);
    }

    public function test_guest_and_regular_user_cannot_manage_accounts_even_by_direct_requests(): void
    {
        $this->get('/users')->assertRedirect('/login');
        $admin = $this->user('ADMIN', true);
        $regular = $this->user('REGULAR');
        $scope = $this->scope();
        $regular->scopes()->attach($scope);
        $this->actingAs($regular)->get('/dashboard')->assertOk()->assertSee('Fundo A')->assertDontSee('Gerenciar usuários');
        $this->get('/users')->assertForbidden();
        $this->get('/users/create')->assertForbidden();
        $this->get('/users/'.$admin->id.'/edit')->assertForbidden();
        $this->post('/users', [])->assertForbidden();
        $this->put('/users/'.$regular->id, ['user_type' => 'Administrador'])->assertForbidden();
        $this->put('/users/'.$admin->id.'/password', [])->assertForbidden();
        $this->delete('/users/'.$admin->id, [])->assertForbidden();
        $admin->delete();
        $this->post('/users/'.$admin->id.'/restore')->assertForbidden();
        $this->assertSame('Usuario', $regular->fresh()->user_type);
    }

    public function test_scopes_are_required_valid_and_cannot_grant_other_funds(): void
    {
        $admin = $this->user('ADMIN', true);
        $user = $this->user('REGULAR');
        $a = $this->scope();
        $b = $this->scope('Fundo B');
        $this->actingAs($admin)->put('/users/'.$user->id, $this->data($user))->assertSessionHasErrors('scope_ids');
        $this->put('/users/'.$user->id, $this->data($user, ['scope_ids' => [999]]))->assertSessionHasErrors('scope_ids.0');
        $this->put('/users/'.$user->id, $this->data($user, ['scope_ids' => [$a->id]]))->assertRedirect('/users');
        $this->assertTrue($user->fresh()->canAccessScope($a->id));
        $this->assertFalse($user->fresh()->canAccessScope($b->id));
        $this->assertTrue($admin->canAccessScope($b->id));
        $this->assertSame([$a->id], UserAudit::firstOrFail()->after['scope_ids']);
    }

    public function test_last_admin_cannot_be_deactivated_deleted_or_demoted(): void
    {
        $admin = $this->user('ADMIN', true);
        $scope = $this->scope();
        $this->actingAs($admin)->put('/users/'.$admin->id, $this->data($admin, ['is_active' => 0]))->assertSessionHasErrors('user_type');
        $this->put('/users/'.$admin->id, $this->data($admin, ['user_type' => 'Usuario', 'scope_ids' => [$scope->id]]))->assertSessionHasErrors('user_type');
        $this->delete('/users/'.$admin->id, ['reason' => 'Teste de exclusão', 'confirmation' => 'ADMIN'])->assertSessionHasErrors('user_type');
        $this->assertTrue($admin->fresh()->isAdmin());
        $this->assertDatabaseCount('user_audits', 0);
        $this->assertDatabaseCount('access_scope_user', 0);
    }

    public function test_delete_requires_confirmation_preserves_history_and_can_be_restored(): void
    {
        $admin = $this->user('ADMIN', true);
        $user = $this->user('REGULAR');
        $scope = $this->scope();
        $user->scopes()->attach($scope);
        $this->actingAs($admin)->delete('/users/'.$user->id, ['reason' => 'Não faz mais parte da equipe', 'confirmation' => 'OUTRO'])->assertSessionHasErrors('confirmation');
        $this->delete('/users/'.$user->id, ['reason' => 'Não faz mais parte da equipe', 'confirmation' => 'REGULAR'])->assertRedirect('/users');
        $this->assertSoftDeleted($user);
        $this->assertDatabaseHas('access_scope_user', ['user_id' => $user->id, 'access_scope_id' => $scope->id]);
        $this->get('/users?status=deleted')->assertOk()->assertSee('REGULAR')->assertSee('Restaurar e ativar');
        $this->post('/users/'.$user->id.'/restore')->assertRedirect('/users');
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNotSoftDeleted($user);
        $this->assertDatabaseCount('user_audits', 2);
    }

    public function test_password_reset_hashes_password_and_invalidates_existing_sessions(): void
    {
        $admin = $this->user('ADMIN', true);
        $user = $this->user('REGULAR');
        $this->actingAs($admin)->put('/users/'.$user->id.'/password', ['password' => 'OutraSenha!12345', 'password_confirmation' => 'OutraSenha!12345'])->assertRedirect('/users');
        $this->assertTrue(Hash::check('OutraSenha!12345', $user->fresh()->password));
        $this->assertFalse(Hash::check('SenhaSegura!123', $user->fresh()->password));
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertStringNotContainsString('OutraSenha', UserAudit::first()->toJson());
        $this->actingAs($user->fresh())->withSession(['auth_version' => 0])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['username' => 'REGULAR', 'password' => 'OutraSenha!12345'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
    }

    public function test_deactivation_removes_an_authenticated_users_access(): void
    {
        $user = $this->user('REGULAR');
        $user->is_active = false;
        $user->save();
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_username_uniqueness_includes_deleted_accounts_and_password_validation(): void
    {
        $admin = $this->user('ADMIN', true);
        $existing = $this->user('REGULAR');
        $existing->delete();
        $scope = $this->scope();
        $this->actingAs($admin)->post('/users', ['name' => 'Outra pessoa', 'username' => 'regular', 'user_type' => 'Usuario', 'is_active' => 1,
            'scope_ids' => [$scope->id], 'password' => 'curta', 'password_confirmation' => 'curta'])->assertSessionHasErrors(['username', 'password']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_reference_import_preserves_scopes_and_does_not_reactivate_deleted_users(): void
    {
        $this->seedReferenceUsers();
        $user = User::where('username', 'FERNANDO')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertCount(1, $user->scopes);
        $user->delete();
        $this->seedReferenceUsers();
        $this->assertSoftDeleted($user);
        $this->assertDatabaseCount('users', 17);
        $this->assertDatabaseCount('access_scope_user', 17);
    }

    public function test_admin_can_be_demoted_when_another_active_admin_exists(): void
    {
        $admin = $this->user('ADMIN', true);
        $this->user('ADMIN2', true);
        $scope = $this->scope();
        $this->actingAs($admin)->put('/users/'.$admin->id, $this->data($admin, ['user_type' => 'Usuario', 'scope_ids' => [$scope->id]]))->assertRedirect('/dashboard');
        $this->actingAs($admin->fresh())->get('/dashboard')->assertOk();
        $this->get('/users')->assertForbidden();
        $this->assertFalse($admin->fresh()->isAdmin());
    }
}
