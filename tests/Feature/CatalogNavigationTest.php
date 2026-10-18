<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_open_catalog_lists_without_private_payloads(): void
    {
        $this->withoutVite();
        $actor = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura123', 'user_type' => 'Administrador']);
        DB::table('contract_inspectors')->insert(['legacy_id' => 1, 'name' => 'Fiscal cadastrado', 'name_key' => 'FISCAL CADASTRADO', 'fund_name_key' => 'FUNDO', 'department_name_key' => '', 'raw_payload' => '{"CPF_Fiscal":"52998224725","Email":"privado@example.com"}']);
        $this->actingAs($actor)->get('/catalogs/list/inspector')->assertOk()
            ->assertSee('Fiscal cadastrado')->assertSeeInOrder(['data-nav-label="Início"', 'data-nav-label="Contratos"', 'data-nav-label="Cadastros"'], false)
            ->assertDontSee('52998224725')->assertDontSee('privado@example.com');
        $this->get('/catalogs/list/manager')->assertOk();
        DB::table('tb_Gestor')->insert(['legacy_id' => 1, 'name' => 'Gestor cadastrado', 'name_key' => 'GESTOR CADASTRADO', 'fund_name_key' => 'FUNDO SAUDE', 'raw_payload' => '{"CPF_Gestor":"52998224725","Email":"gestor-privado@example.com"}']);
        $this->get('/catalogs/list/manager-fund')->assertOk()
            ->assertSee('Gestor_Fundo')->assertSee('Gestor cadastrado')->assertSee('FUNDO SAUDE')
            ->assertSee('href="'.route('catalogs.list', 'manager-fund').'"', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('52998224725')->assertDontSee('gestor-privado@example.com');
        $this->get('/catalogs/list/budget')->assertOk();
        $this->get('/catalogs/list/unknown')->assertNotFound();
    }

    public function test_catalog_lists_remain_restricted_to_administrators(): void
    {
        $actor = User::create(['name' => 'Usuario', 'username' => 'USER', 'password' => 'SenhaSegura123']);
        foreach (['inspector', 'manager', 'manager-fund', 'budget'] as $kind) {
            $this->actingAs($actor)->get('/catalogs/list/'.$kind)->assertForbidden();
        }
    }
}
