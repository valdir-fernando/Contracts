<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnAccountEditingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, string $role = 'Usuario'): User
    {
        return User::create(['name' => $username, 'username' => $username, 'password' => 'PasswordTest123', 'user_type' => $role]);
    }

    public function test_regular_user_can_change_own_username_but_cannot_target_others_or_change_permissions(): void
    {
        $actor = $this->user('ACTOR');
        $other = $this->user('OTHER');
        $data = ['name' => 'Atualizado', 'username' => 'novo.apelido', 'current_password' => 'PasswordTest123', 'version' => 0];
        $this->actingAs($actor)->withSession(['auth_version' => 0]);
        $this->put('/account', $data + ['user_id' => $other->id])->assertSessionHasErrors('user_id');
        $this->put('/account', $data + ['user_type' => 'Administrador', 'is_active' => 0, 'affiliations' => ['fund:1']])->assertSessionHasErrors(['user_type', 'is_active', 'affiliations']);
        $this->put('/account', $data)->assertRedirect('/account');
        $this->assertSame('NOVO.APELIDO', $actor->fresh()->username);
        $this->assertSame('OTHER', $other->fresh()->username);
        $this->assertDatabaseHas('user_audits', ['actor_id' => $actor->id, 'user_id' => $actor->id, 'action' => 'updated']);
        $this->put('/account', $data)->assertStatus(409);
    }

    public function test_master_can_edit_own_affiliation_but_cannot_demote_last_master(): void
    {
        $actor = $this->user('ADMIN', User::MASTER);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Saúde', 'name_key' => 'SAUDE', 'raw_payload' => '{}']);
        $data = ['name' => 'Administrador', 'username' => 'ADMIN', 'current_password' => 'PasswordTest123', 'version' => 0,
            'user_type' => 'Usuario', 'is_active' => 1, 'affiliations_present' => 1, 'affiliations' => ['fund:'.$fund]];
        $this->actingAs($actor)->withSession(['auth_version' => 0]);
        $this->put('/account', $data)->assertSessionHasErrors('user_type');
        $this->assertTrue($actor->fresh()->isAdmin());
        $this->assertDatabaseCount('user_audits', 0);
        $data['user_type'] = User::MASTER;
        $this->put('/account', $data)->assertRedirect('/account');
        $this->assertSame('Saúde', $actor->fresh()->fund);
        $this->assertNull($actor->fresh()->department);
        $this->assertSame($fund, $actor->fresh()->scopes->sole()->fund_id);
    }

    public function test_master_deactivating_own_account_logs_out_and_preserves_other_master(): void
    {
        $actor = $this->user('ADMIN', User::MASTER);
        $other = $this->user('OTHER_ADMIN', User::MASTER);
        $this->actingAs($actor)->withSession(['auth_version' => 0])->put('/account', [
            'name' => 'Admin', 'current_password' => 'PasswordTest123', 'version' => 0, 'is_active' => 0,
        ])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertFalse($actor->fresh()->is_active);
        $this->assertTrue($other->fresh()->isAdmin());
    }
}
