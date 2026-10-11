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
}
