<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_cannot_select_or_submit_master_role_in_own_account(): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'PasswordTest123', 'user_type' => 'Administrador']);
        $version = $admin->session_version;
        $this->actingAs($admin)->get('/account')->assertOk()
            ->assertDontSee('value="Administrador Master"', false)
            ->assertDontSee('name="user_type"', false);

        $this->putJson('/account', ['name' => 'Admin', 'current_password' => 'PasswordTest123',
            'version' => $version, 'user_type' => User::MASTER])
            ->assertUnprocessable()->assertJsonValidationErrors('user_type');

        $this->assertSame('Administrador', $admin->fresh()->user_type);
        $this->assertSame($version, $admin->fresh()->session_version);
        $this->assertDatabaseCount('user_audits', 0);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_admin_cannot_create_promote_edit_reset_delete_or_restore_administrative_accounts(): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'PasswordTest123', 'user_type' => 'Administrador']);
        $master = User::create(['name' => 'Master', 'username' => 'MASTER', 'password' => 'PasswordTest123', 'user_type' => User::MASTER]);
        $regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'PasswordTest123']);
        $scope = AccessScope::create(['scope_key' => 'test', 'fund' => 'Fundo']);
        $data = ['name' => 'Novo', 'username' => 'NOVO', 'password' => 'PasswordTest123', 'password_confirmation' => 'PasswordTest123', 'user_type' => User::MASTER, 'is_active' => 1, 'scope_ids' => [$scope->id]];
        $this->actingAs($admin)->withSession(['auth_version' => 0]);
        foreach ([User::MASTER, 'Administrador'] as $role) {
            $this->post('/users', array_replace($data, ['user_type' => $role]))->assertForbidden();
            $this->put('/users/'.$regular->id, array_replace($data, ['password' => null, 'password_confirmation' => null, 'user_type' => $role, 'version' => 0]))->assertForbidden();
        }
        foreach ([$master, $admin] as $target) {
            $this->get('/users/'.$target->id.'/edit')->assertForbidden();
            $this->put('/users/'.$target->id.'/password', ['password' => 'OtherPassword123', 'password_confirmation' => 'OtherPassword123', 'version' => 0])->assertForbidden();
            $this->delete('/users/'.$target->id, ['version' => 0, 'reason' => 'Teste de hierarquia', 'confirmation' => $target->username])->assertForbidden();
        }
        $this->put('/account', ['name' => 'Admin', 'current_password' => 'PasswordTest123', 'user_type' => User::MASTER])->assertSessionHasErrors('user_type');
        $master->delete();
        $this->post('/users/'.$master->id.'/restore', ['version' => 0])->assertForbidden();
        $this->assertSame('Usuario', $regular->fresh()->user_type);
        $this->assertDatabaseCount('user_audits', 0);
    }

    public function test_master_can_create_all_roles_and_delete_admin_but_last_master_is_protected(): void
    {
        $this->withoutVite();
        $master = User::create(['name' => 'Master', 'username' => 'MASTER', 'password' => 'PasswordTest123', 'user_type' => User::MASTER]);
        $scope = AccessScope::create(['scope_key' => 'test', 'fund' => 'Fundo']);
        $this->actingAs($master)->withSession(['auth_version' => 0]);
        $this->delete('/users/'.$master->id, ['version' => 0, 'reason' => 'Teste de exclusão', 'confirmation' => 'MASTER'])->assertSessionHasErrors('user_type');
        foreach (User::ROLES as $index => $role) {
            $this->post('/users', ['name' => 'Novo', 'username' => 'NEW'.$index, 'password' => 'PasswordTest123', 'password_confirmation' => 'PasswordTest123',
                'user_type' => $role, 'is_active' => 1, 'scope_ids' => [$scope->id]])->assertRedirect('/users');
        }
        $admin = User::where('user_type', 'Administrador')->firstOrFail();
        $this->delete('/users/'.$admin->id, ['version' => 0, 'reason' => 'Teste de exclusão', 'confirmation' => $admin->username])->assertRedirect('/users');
        $this->assertSoftDeleted($admin);
        $this->post('/users/'.$admin->id.'/restore', ['version' => $admin->fresh()->session_version])->assertRedirect('/users');
        $this->assertNotSoftDeleted($admin);
        $this->assertTrue($master->fresh()->isAdmin());
        $this->get('/users')->assertOk()->assertSee('Administrador Master');
        $this->get('/account')->assertOk()->assertSee('Administrador Master');
    }

    public function test_promotion_command_preserves_password_and_affiliations_and_is_idempotent(): void
    {
        $user = User::create(['name' => 'Fernando', 'username' => 'FERNANDO', 'password' => 'PasswordTest123', 'user_type' => 'Administrador']);
        $scope = AccessScope::create(['scope_key' => 'test', 'fund' => 'Fundo']);
        $user->scopes()->attach($scope);
        $password = $user->password;
        $this->artisan('contracts:promote-master', ['username' => 'FERNANDO'])->assertSuccessful();
        $this->assertTrue($user->fresh()->isMaster());
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame([$scope->id], $user->fresh()->scopes->modelKeys());
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertDatabaseHas('user_audits', ['user_id' => $user->id, 'actor_id' => null]);
        $this->artisan('contracts:promote-master', ['username' => 'FERNANDO'])->assertSuccessful();
        $this->assertDatabaseCount('user_audits', 1);
        $this->assertSame(1, $user->fresh()->session_version);
    }
}
