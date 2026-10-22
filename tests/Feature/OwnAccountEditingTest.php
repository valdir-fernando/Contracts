<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OwnAccountEditingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, string $role = 'Usuario'): User
    {
        return User::create(['name' => $username, 'username' => $username, 'password' => 'PasswordTest123', 'user_type' => $role]);
    }

    public function test_details_save_needs_no_password_and_cannot_change_it(): void
    {
        $actor = $this->user('ACTOR');
        $hash = $actor->password;
        $this->actingAs($actor)->withSession(['auth_version' => 0])->put('/account', [
            'name' => 'Nome atualizado', 'version' => 0,
            'password' => 'OtherPassword123', 'password_confirmation' => 'OtherPassword123',
        ])->assertSessionHasNoErrors()->assertRedirect('/account');
        $this->assertSame('Nome atualizado', $actor->fresh()->name);
        $this->assertSame($hash, $actor->fresh()->password);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $actor->id, 'action' => 'account_updated']);
        $this->assertDatabaseMissing('activity_events', ['action' => 'own_password_changed']);
    }

    public function test_password_save_is_independent_audited_and_preserves_other_account_data(): void
    {
        $actor = $this->user('ACTOR');
        $other = $this->user('OTHER');
        $before = $actor->only(['name', 'username', 'email', 'user_type', 'is_active']);
        $this->actingAs($actor)->withSession(['auth_version' => 0]);
        $payload = ['current_password' => 'PasswordTest123', 'password' => 'OtherPassword123',
            'password_confirmation' => 'OtherPassword123', 'version' => 0];
        foreach ([['current_password' => 'incorrect'], ['password_confirmation' => 'incorrect'],
            ['password' => 'short'], ['version' => null]] as $change) {
            $this->put('/account/password', array_replace($payload, $change))->assertSessionHasErrors();
            $this->assertTrue(Hash::check('PasswordTest123', $actor->fresh()->password));
        }
        $this->put('/account/password', array_replace($payload, ['version' => 5]))->assertStatus(409);
        $this->assertDatabaseMissing('activity_events', ['action' => 'own_password_changed']);
        $this->put('/account/password', $payload + ['name' => 'Indevido', 'username' => 'INDEVIDO',
            'user_id' => $other->id, 'user_type' => User::MASTER, 'is_active' => 0])
            ->assertRedirect('/account')->assertSessionHas('status', 'Senha alterada com sucesso.')
            ->assertSessionHas('auth_version', 1);
        $this->assertSame($before, $actor->fresh()->only(array_keys($before)));
        $this->assertTrue(Hash::check('OtherPassword123', $actor->fresh()->password));
        $this->assertTrue(Hash::check('PasswordTest123', $other->fresh()->password));
        $this->assertSame(1, $actor->fresh()->session_version);
        $this->assertNull($actor->fresh()->remember_token);
        $event = ActivityEvent::where('action', 'own_password_changed')->sole();
        $this->assertSame([], $event->metadata);
        $this->get('/account')->assertOk();
        $this->withSession(['auth_version' => 0])->get('/account')->assertRedirect('/login');
    }

    public function test_account_page_has_separate_forms_and_password_divider(): void
    {
        $this->withoutVite();
        $response = $this->actingAs($this->user('ACTOR'))->get('/account')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//form[@action="'.route('account.update').'"]//input[@type="password"]')->length);
        $this->assertSame(3, $xpath->query('//form[@action="'.route('account.password').'"]//input[@type="password" and @required]')->length);
        $response->assertSee('account-password-divider')->assertSee('Alterar Senha');
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
