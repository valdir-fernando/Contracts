<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use App\Services\Legacy\ManagerImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SelectedFundTest extends TestCase
{
    use RefreshDatabase;

    private int $fund;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A',
            'document' => '11222333000181', 'raw_payload' => json_encode(['End_Fundo' => 'Rua do Fundo', 'private' => 'SOURCE PRIVATE'])]);
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura!123', 'user_type' => 'Administrador']);
    }

    private function regular(): User
    {
        $user = User::create(['name' => 'Pessoa', 'username' => 'REGULAR', 'password' => 'SenhaSegura!123']);
        $scope = AccessScope::create(['scope_key' => 'selected-fund', 'fund' => 'Fundo A', 'fund_id' => $this->fund]);
        $user->scopes()->attach($scope);

        return $user;
    }

    private function manager(int $id = 1): void
    {
        DB::table('contract_managers')->insert(['legacy_id' => $id, 'name' => 'Gestor do Fundo', 'name_key' => 'GESTOR DO FUNDO',
            'fund_name_key' => 'FUNDO A', 'raw_payload' => json_encode(['Nome_Gestor' => 'Gestor do Fundo', 'Cargo' => 'Presidente',
                'CPF_Gestor' => '52998224725', 'RG_Gestor' => 'RG PRIVATE', 'Email' => 'PRIVATE EMAIL', 'Endereco' => 'PRIVATE ADDRESS'])]);
    }

    public function test_login_requires_an_existing_fund_and_public_screen_only_exposes_names(): void
    {
        $this->manager();
        $this->get('/login')->assertOk()->assertSee('Fundo A')->assertSee('name="fund_id" required', false)
            ->assertDontSee('52998224725')->assertDontSee('SOURCE PRIVATE')->assertDontSee('Gestor do Fundo');
        foreach ([null, 9999] as $fund) {
            $this->post('/login', ['fund_id' => $fund, 'username' => 'ADMIN', 'password' => 'SenhaSegura!123'])->assertSessionHasErrors('fund_id');
            $this->assertGuest();
        }
    }

    public function test_selected_fund_is_saved_in_session_and_replaces_home_breadcrumb(): void
    {
        $this->post('/login', ['fund_id' => $this->fund, 'username' => 'ADMIN', 'password' => 'SenhaSegura!123'])
            ->assertRedirect('/dashboard')->assertSessionHas('fund_id', $this->fund);
        $this->get('/dashboard')->assertOk()->assertSee('<strong class="selected-fund-name">Fundo A</strong>', false)
            ->assertDontSee('Gestão de contratos <span class="breadcrumb-divider"', false);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->admin->id, 'action' => 'login']);
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('fund_id');
    }

    public function test_regular_user_cannot_login_to_an_unauthorized_fund(): void
    {
        $user = $this->regular();
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        $this->post('/login', ['fund_id' => $other, 'username' => $user->username, 'password' => 'SenhaSegura!123'])
            ->assertSessionHasErrors('fund_id')->assertSessionMissing('fund_id');
        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertDatabaseMissing('activity_events', ['actor_id' => $user->id, 'action' => 'login']);
    }

    public function test_revoked_fund_scope_ends_session(): void
    {
        $user = $this->regular();
        $this->post('/login', ['fund_id' => $this->fund, 'username' => $user->username, 'password' => 'SenhaSegura!123'])->assertRedirect('/dashboard');
        $user->scopes()->detach();
        $this->get('/dashboard')->assertRedirect('/login')->assertSessionMissing('fund_id');
        $this->assertGuest();
    }

    public function test_remember_cookie_without_selected_fund_requires_a_new_login(): void
    {
        $user = $this->regular();
        $user->forceFill(['remember_token' => 'remember-test-token'])->save();
        $cookie = Auth::guard()->getRecallerName();
        $this->withCookie($cookie, $user->id.'|'.$user->remember_token.'|'.$user->password)
            ->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_creation_uses_current_catalog_and_preserves_supplier_and_old_input(): void
    {
        $this->manager();
        $this->actingAs($this->admin)->withSession(['fund_id' => $this->fund])->get('/contracts/create')
            ->assertOk()->assertViewHas('values', fn ($values) => $values['fund_id'] === $this->fund && $values['end_fundo'] === 'Rua do Fundo'
                && $values['nome_gestor'] === 'Gestor do Fundo' && $values['cargo_gestor'] === 'Presidente' && $values['cpf_gestor'] === '52998224725')
            ->assertDontSee('PRIVATE EMAIL')->assertDontSee('PRIVATE ADDRESS')->assertDontSee('SOURCE PRIVATE');
        $this->withSession(['_old_input' => ['nome_gestor' => 'Valor digitado']])->get('/contracts/create')
            ->assertOk()->assertSee('value="Valor digitado"', false);
        $supplier = DB::table('suppliers')->insertGetId(['name' => 'Fornecedor', 'name_key' => 'FORNECEDOR', 'document' => '11222333000181', 'document_key' => '11222333000181', 'raw_payload' => '{}']);
        $this->get('/contracts/create?supplier='.$supplier)->assertViewHas('values', fn ($values) => $values['fund_id'] === $this->fund && $values['fornecedor_nome_snapshot'] === 'Fornecedor');
    }

    public function test_defaults_are_authorized_projected_and_personal_fields_are_restricted(): void
    {
        $this->manager();
        $user = $this->regular();
        $this->actingAs($user)->getJson('/funds/'.$this->fund.'/contract-defaults')->assertOk()
            ->assertJsonPath('values.nome_gestor', 'Gestor do Fundo')->assertJsonMissingPath('values.cpf_gestor')
            ->assertJsonMissingPath('values.rg_gestor')->assertDontSee('PRIVATE EMAIL')->assertHeader('Cache-Control', 'no-store, private');
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        $this->getJson('/funds/'.$other.'/contract-defaults')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/funds/'.$this->fund.'/contract-defaults')->assertOk()->assertJsonPath('values.cpf_gestor', '52998224725');
    }

    public function test_ambiguous_managers_are_not_automatically_selected(): void
    {
        $this->manager(1);
        $this->manager(2);
        $this->actingAs($this->admin)->getJson('/funds/'.$this->fund.'/contract-defaults')->assertOk()
            ->assertJsonPath('values.nome_gestor', null)->assertJsonPath('values.cpf_gestor', null);
    }

    public function test_editing_a_contract_preserves_its_existing_manager(): void
    {
        $this->manager();
        $contract = Contract::create(['numero' => '001/26', 'fund_id' => $this->fund, 'nome_gestor' => 'Gestor histórico', 'quality_issues' => []]);
        $this->actingAs($this->admin)->withSession(['fund_id' => $this->fund])->get('/contracts/'.$contract->id.'/edit')
            ->assertOk()->assertSee('value="Gestor histórico"', false)->assertDontSee('value="Gestor do Fundo"', false);
    }

    public function test_manager_import_preserves_raw_source_is_idempotent_and_preserves_changed_records(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'manager-test-');
        try {
            $sql = "INSERT INTO `tb_Gestor` (`ID_Gestor`, `Fundo`, `Nome_Gestor`, `CPF_Gestor`, `RG_Gestor`, `Cargo`, `Email`) VALUES (1, 'Fundo A', 'Gestor importado', '52998224725', 'RG PRIVATE', 'Presidente', 'PRIVATE EMAIL');";
            file_put_contents($path, $sql);
            $importer = app(ManagerImporter::class);
            $this->assertSame(1, $importer->import($path)['imported_count']);
            $this->assertSame(1, $importer->import($path)['skipped_count']);
            $this->assertDatabaseCount('contract_managers', 1);
            $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_Gestor', 'legacy_id' => '1']);
            file_put_contents($path, str_replace('Gestor importado', 'Gestor alterado', $sql));
            $this->assertSame(1, $importer->import($path)['rejected_count']);
            $this->assertDatabaseHas('contract_managers', ['name' => 'Gestor importado']);
            $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_Gestor', 'status' => 'conflict']);
            $this->assertDatabaseCount('contracts', 0);
        } finally {
            unlink($path);
        }
    }
}
