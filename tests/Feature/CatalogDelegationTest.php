<?php

namespace Tests\Feature;

use App\Infrastructure\Inspectors\InspectorRegistration;
use App\Models\AccessScope;
use App\Models\User;
use App\Models\UserAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogDelegationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $username, string $role = 'Usuario'): User
    {
        return User::create(['name' => $username, 'username' => $username, 'password' => 'PasswordTest123', 'user_type' => $role]);
    }

    public static function catalogs(): array
    {
        return [['inspector', '/catalogs/list/inspector'], ['contract-steward', '/catalogs/list/contract-steward'],
            ['manager-fund', '/catalogs/list/manager-fund'], ['fund', '/funds'], ['department', '/catalogs'], ['budget', '/catalogs/list/budget']];
    }

    public static function administrativeRoles(): array
    {
        return [['Administrador'], [User::MASTER]];
    }

    #[DataProvider('administrativeRoles')]
    public function test_administrators_automatically_access_every_catalog_without_individual_grants(string $role): void
    {
        $this->withoutVite();
        $user = $this->account('ADMIN', $role);
        $user->catalog_permissions = [];
        $user->save();
        $this->actingAs($user)->withSession(['auth_version' => 0]);
        foreach (array_keys(User::CATALOGS) as $kind) {
            $this->assertTrue($user->canAccessCatalog($kind));
        }
        foreach (self::catalogs() as [, $url]) {
            $this->get($url)->assertOk();
        }
        $this->get('/suppliers')->assertOk();
        $this->get('/users/create')->assertOk()->assertSee('acesso automático a todos os cadastros');
    }

    #[DataProvider('catalogs')]
    public function test_each_catalog_requires_its_own_permission(string $kind, string $url): void
    {
        $this->withoutVite();
        $user = $this->account('REGULAR');
        $this->actingAs($user)->withSession(['auth_version' => 0])->get($url)->assertForbidden();
        $user->catalog_permissions = [$kind];
        $user->save();
        $this->actingAs($user->fresh())->get($url)->assertOk();
        $this->get('/users')->assertForbidden();
        $this->post('/catalogs', ['kind' => 'scope', 'fund_id' => 1])->assertForbidden();
        $this->get('/inspectors/decree')->assertForbidden();
    }

    public function test_admin_grants_one_catalog_and_revokes_it_with_audit_and_session_version(): void
    {
        $this->withoutVite();
        $admin = $this->account('ADMIN', 'Administrador');
        $user = $this->account('REGULAR');
        $scope = AccessScope::create(['scope_key' => 'test', 'fund' => 'Fundo']);
        $user->scopes()->attach($scope);
        $data = ['name' => $user->name, 'username' => $user->username, 'user_type' => 'Usuario', 'is_active' => 1,
            'scope_ids' => [$scope->id], 'version' => 0, 'catalog_permissions_present' => 1, 'catalog_permissions' => ['inspector']];
        $this->actingAs($admin)->withSession(['auth_version' => 0])->put('/users/'.$user->id, $data)->assertRedirect('/users');
        $this->assertSame(['inspector'], $user->fresh()->catalog_permissions);
        $this->assertSame(['inspector'], UserAudit::latest('id')->first()->after['catalog_permissions']);
        $this->actingAs($user->fresh())->withSession(['auth_version' => 1])->get('/catalogs/list/inspector')->assertOk();
        $this->get('/catalogs/list/contract-steward')->assertForbidden();
        $this->get('/funds')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->put('/account', ['name' => 'REGULAR', 'current_password' => 'PasswordTest123', 'catalog_permissions' => ['fund']])->assertSessionHasErrors('catalog_permissions');
        $data['version'] = 1;
        unset($data['catalog_permissions']);
        $this->actingAs($admin)->withSession(['auth_version' => 0])->put('/users/'.$user->id, $data)->assertRedirect('/users');
        $this->assertSame([], $user->fresh()->catalog_permissions);
        $this->actingAs($user->fresh())->withSession(['auth_version' => 2])->get('/catalogs/list/inspector')->assertForbidden();
        $this->actingAs($user->fresh())->withSession(['auth_version' => 1])->get('/dashboard')->assertRedirect('/login');
    }

    public function test_delegate_can_maintain_inspectors_only_in_own_department_and_cannot_cross_catalogs(): void
    {
        $this->withoutVite();
        $user = $this->account('DELEGATE');
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'MUNICIPIO DE NIQUELANDIA', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{}']);
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        $other = DB::table('contract_departments')->insertGetId(['name' => 'Saúde', 'name_key' => 'SAUDE', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'test', 'fund_id' => $fund, 'department_id' => $department, 'fund' => 'MUNICIPIO DE NIQUELANDIA', 'department' => 'Educação']);
        $user->scopes()->attach($scope);
        $user->catalog_permissions = ['inspector'];
        $user->save();
        $data = ['Nome_Fiscal' => 'Fiscal autorizado', 'fund_id' => $fund, 'department_id' => $department];
        $this->actingAs($user)->withSession(['auth_version' => 0])->post('/inspectors', $data)->assertRedirect();
        $record = DB::table('contract_inspectors')->first();
        $this->get('/catalogs/list/inspector?edit='.$record->id)->assertOk()->assertSee('FISCAL AUTORIZADO');
        $this->post('/inspectors', array_replace($data, ['department_id' => $other]))->assertForbidden();
        $this->post('/contract-stewards', $data)->assertForbidden();
        $foreign = DB::table('contract_inspectors')->insertGetId(['name' => 'Fiscal outro órgão', 'name_key' => 'FISCAL OUTRO ORGAO', 'fund_name_key' => 'MUNICIPIO DE NIQUELANDIA', 'department_name_key' => 'SAUDE', 'raw_payload' => '{}']);
        $this->get('/catalogs/list/inspector')->assertOk()->assertDontSee('Fiscal outro órgão');
        $this->get('/catalogs/list/inspector?edit='.$foreign)->assertNotFound();
        $this->put('/inspectors/'.$foreign, $data + ['revision' => 'invalid'])->assertNotFound();
        $revision = app(InspectorRegistration::class)->revision($record);
        $this->put('/inspectors/'.$record->id, $data + ['revision' => $revision])->assertRedirect();
        $revision = app(InspectorRegistration::class)->revision(DB::table('contract_inspectors')->find($record->id));
        $this->delete('/inspectors/'.$record->id, ['revision' => $revision, 'confirmation' => 1])->assertRedirect();
        $this->assertDatabaseHas('contract_inspectors', ['id' => $foreign, 'deleted_at' => null]);
        $this->assertDatabaseCount('activity_events', 3);
    }

    public function test_supplier_permission_allows_creation_and_own_catalog_but_not_unrelated_records(): void
    {
        $this->withoutVite();
        $user = $this->account('DELEGATE');
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'supplier', 'fund_id' => $fund, 'fund' => 'Fundo']);
        $user->scopes()->attach($scope);
        $user->catalog_permissions = ['supplier'];
        $user->save();
        $this->actingAs($user)->withSession(['auth_version' => 0])->post('/catalogs', ['kind' => 'supplier', 'name' => 'Fornecedor próprio', 'document' => '11222333000181', 'Endereco' => 'Endereço completo de teste'])->assertRedirect('/suppliers');
        $supplier = DB::table('suppliers')->first();
        $this->get('/suppliers')->assertOk()->assertSee('Fornecedor próprio');
        $this->get('/suppliers/'.$supplier->id)->assertOk()->assertSee('Editar fornecedor');
        $foreign = DB::table('suppliers')->insertGetId(['name' => 'Fornecedor alheio', 'name_key' => 'FORNECEDOR ALHEIO', 'document_key' => '52998224725', 'raw_payload' => '{}']);
        $this->get('/suppliers')->assertOk()->assertDontSee('Fornecedor alheio');
        $this->get('/suppliers/'.$foreign)->assertNotFound();
        $this->put('/suppliers/'.$foreign, [])->assertNotFound();
    }
}
