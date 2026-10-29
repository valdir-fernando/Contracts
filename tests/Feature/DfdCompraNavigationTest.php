<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DfdCompraNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_requires_authentication(): void
    {
        $this->get('/dfd-compra')->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_open_the_page_and_menu_marks_it_as_current(): void
    {
        $this->withoutVite();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A', 'raw_payload' => '{}']);
        foreach (['Administrador', 'Usuario'] as $index => $role) {
            $actor = User::create(['name' => 'Pessoa exemplo', 'username' => 'DFD'.$index, 'password' => 'SenhaSegura123', 'user_type' => $role]);
            $scope = AccessScope::create(['scope_key' => 'dfd-'.$index, 'fund_id' => $fund, 'fund' => 'Fundo A']);
            $actor->scopes()->attach($scope);
            $this->actingAs($actor)->withSession(['fund_id' => $fund])->get('/dfd-compra')->assertOk()->assertViewIs('dfd-compra.index')
                ->assertSee('<h1>DFD - Documento de Formalização de Demanda</h1>', false)
                ->assertSee('href="'.route('dfd-compra.index').'" aria-label="DFD Compra" data-nav-label="DFD Compra" class="nav-link selected"', false)
                ->assertSee('data-dfd-class-open', false)->assertSee('aria-controls="dfd-class-dialog"', false)
                ->assertSee('DFD - Documento de Formalização de Demanda')
                ->assertSee('class="modal-close" data-dfd-class-close aria-label="Fechar DFD"', false)
                ->assertDontSee('data-dfd-class-confirm', false);
        }
    }

    public function test_class_lookup_requires_authentication_and_an_authorized_selected_fund(): void
    {
        $this->getJson('/dfd-compra/classes')->assertUnauthorized();
        $actor = User::create(['name' => 'Pessoa', 'username' => 'NOFUND', 'password' => 'SenhaSegura123']);
        $this->actingAs($actor)->getJson('/dfd-compra/classes')->assertForbidden();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Restrito', 'name_key' => 'RESTRITO', 'raw_payload' => '{}']);
        $this->withSession(['fund_id' => $fund])->getJson('/dfd-compra/classes')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_class_lookup_returns_only_distinct_nonempty_active_classes_in_order(): void
    {
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A', 'raw_payload' => '{}']);
        $actor = User::create(['name' => 'Pessoa', 'username' => 'CLASSES', 'password' => 'SenhaSegura123']);
        $scope = AccessScope::create(['scope_key' => 'dfd-classes', 'fund_id' => $fund, 'fund' => 'Fundo A']);
        $actor->scopes()->attach($scope);
        foreach ([['COMUNICAÇÃO', 0], ['ALIMENTOS', 0], ['COMUNICAÇÃO', 0], ['INATIVA', 1], ['   ', 0]] as $index => [$class, $inactive]) {
            DB::table('tb_Produtos_Prodata')->insert(['ID_Prodata' => $index + 1, 'Classe' => $class, 'Descricao' => 'Descrição interna', 'UND' => 'UN', 'Inativo' => $inactive]);
        }
        $this->actingAs($actor)->withSession(['fund_id' => $fund])->getJson('/dfd-compra/classes')
            ->assertOk()->assertExactJson(['classes' => ['ALIMENTOS', 'COMUNICAÇÃO']])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_panel_displays_the_selected_catalog_class_without_creating_a_dfd(): void
    {
        $this->withoutVite();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo A', 'name_key' => 'FUNDO A', 'raw_payload' => '{}']);
        $actor = User::create(['name' => 'Pessoa', 'username' => 'DFDPANEL', 'password' => 'SenhaSegura123', 'user_type' => 'Administrador']);
        $class = 'COMUNICAÇÃO & EQUIPAMENTOS <ESPECIAIS>';
        DB::table('tb_Produtos_Prodata')->insert(['ID_Prodata' => 1, 'Classe' => $class, 'Descricao' => 'Produto', 'UND' => 'UN', 'Inativo' => 0]);
        $this->actingAs($actor)->withSession(['fund_id' => $fund])
            ->get('/dfd-compra?'.http_build_query(['classe' => $class]))->assertOk()
            ->assertSee('value="'.e($class).'" readonly data-dfd-class-panel', false);
        $this->get('/dfd-compra?classe=INEXISTENTE')->assertStatus(422);
        $this->get('/dfd-compra')->assertOk()->assertSee('value="" readonly data-dfd-class-panel', false);
        $this->assertDatabaseCount('tb_DFD', 0);
        $this->assertDatabaseCount('tb_N_DFD', 0);
    }
}
