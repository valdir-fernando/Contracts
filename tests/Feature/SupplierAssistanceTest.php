<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierAssistanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_assistance_is_scoped_masks_personal_catalog_and_prefills_new_contract(): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestPassword12345']);
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'supplier-scope'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $user->scopes()->attach($scope);
        $suppliers = [];
        foreach (['Empresa Visível' => '11222333000181', 'Empresa Secreta' => '19131243000197', 'Pessoa Protegida' => '52998224725'] as $name => $document) {
            $suppliers[$name] = DB::table('suppliers')->insertGetId(['name' => $name, 'name_key' => $name, 'document' => $document, 'document_key' => $document, 'raw_payload' => '{}']);
        }
        foreach (['Empresa Visível', 'Pessoa Protegida'] as $name) {
            Contract::create(['supplier_id' => $suppliers[$name], 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        }
        $this->actingAs($user)->get('/suppliers')->assertOk()->assertSee('Empresa Visível')->assertDontSee('Empresa Secreta')->assertDontSee('Pessoa Protegida')->assertDontSee('52998224725');
        $this->get('/suppliers?q=11.222.333%2F0001-81')->assertSee('Empresa Visível');
        $this->get('/contracts/create?supplier='.$suppliers['Empresa Visível'])->assertOk()->assertSee('Empresa Visível')->assertSee('11222333000181');
        foreach (['Empresa Secreta', 'Pessoa Protegida'] as $name) {
            $this->get('/contracts/create?supplier='.$suppliers[$name])->assertNotFound();
        }
        $this->actingAs($admin)->get('/suppliers')->assertSee('Empresa Secreta')->assertSee('Pessoa Protegida');
        $this->get('/contracts/create?supplier='.$suppliers['Pessoa Protegida'])->assertOk()->assertSee('52998224725');
        $this->actingAs($user);
        $user->scopes()->detach();
        $this->get('/suppliers')->assertForbidden();
        $this->get('/contracts/create?supplier='.$suppliers['Empresa Visível'])->assertForbidden();
    }

    public function test_supplier_modal_keeps_validation_and_success_on_the_supplier_list(): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $this->actingAs($admin)->from('/suppliers')->post('/catalogs', [
            'kind' => 'supplier', 'name' => 'Fornecedor novo', 'document' => '123', 'return_to' => 'suppliers.index', 'Endereco' => 'Rua de teste',
        ])->assertRedirect('/suppliers')->assertSessionHasErrors(['document'], null, 'catalog-supplier');
        $this->get('/suppliers')->assertOk()->assertSee('data-modal-auto-open', false)->assertSee('Fornecedor novo');
        $this->assertDatabaseMissing('suppliers', ['name' => 'Fornecedor novo']);

        $this->post('/catalogs', [
            'kind' => 'supplier', 'name' => 'Fornecedor novo', 'document' => '11.222.333/0001-81', 'return_to' => 'suppliers.index', 'Endereco' => 'Rua de teste',
        ])->assertRedirect('/suppliers')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('suppliers', ['name' => 'Fornecedor novo', 'document_key' => '11222333000181']);
        $this->get('/suppliers')->assertSee('Fornecedor novo');
    }

    public function test_catalog_modal_creation_requires_admin_and_limits_return_destinations(): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestPassword12345']);
        $this->actingAs($user)->post('/catalogs', [
            'kind' => 'supplier', 'name' => 'Negado', 'document' => '11222333000181', 'return_to' => 'suppliers.index',
        ])->assertForbidden();
        $this->actingAs($admin)->from('/catalogs')->post('/catalogs', [
            'kind' => 'supplier', 'name' => 'Destino inválido', 'document' => '11222333000181', 'return_to' => 'https://example.com',
        ])->assertRedirect('/catalogs')->assertSessionHasErrors(['return_to'], null, 'catalog-supplier');
        $this->assertDatabaseMissing('suppliers', ['name' => 'Destino inválido']);
        $this->post('/catalogs', ['kind' => 'department', 'name' => 'Secretaria nova', 'return_to' => 'suppliers.index'])
            ->assertRedirect('/catalogs');
        $this->assertDatabaseHas('contract_departments', ['name' => 'Secretaria nova']);
        $this->get('/catalogs')->assertSee('Secretaria nova');
    }
}
