<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\User;
use App\Policies\ContractPolicy;
use App\Services\ContractWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ContractIssuancePermissionTest extends TestCase
{
    use RefreshDatabase;

    public static function administrativeRoles(): array
    {
        return [['Administrador'], [User::MASTER]];
    }

    #[DataProvider('administrativeRoles')]
    public function test_administrator_can_grant_and_revoke_issuance_without_granting_other_catalogs(string $role): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'PasswordTest123', 'user_type' => $role]);
        $this->assertTrue($admin->canIssueContracts());
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'issuer', 'fund_id' => $fund, 'fund' => 'Fundo']);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'PasswordTest123']);
        $user->scopes()->attach($scope);
        $data = ['name' => 'Regular', 'username' => 'REGULAR', 'user_type' => 'Usuario', 'is_active' => 1, 'version' => 0,
            'scope_ids' => [$scope->id], 'catalog_permissions_present' => 1, 'catalog_permissions' => ['contract-issuance']];
        $this->actingAs($admin)->withSession(['auth_version' => 0])->put('/users/'.$user->id, $data)->assertRedirect('/users');
        $this->assertTrue($user->fresh()->canIssueContracts());
        $this->assertFalse($user->fresh()->canAccessCatalog('fund'));
        $this->assertFalse($user->fresh()->isAdmin());
        $this->actingAs($user->fresh())->withSession(['auth_version' => 1, 'fund_id' => $fund])->get('/contracts/create')->assertOk();
        $data['version'] = 1;
        $data['catalog_permissions'] = [];
        $this->actingAs($admin)->withSession(['auth_version' => 0])->put('/users/'.$user->id, $data)->assertRedirect('/users');
        $this->assertFalse($user->fresh()->canIssueContracts());
        $this->assertDatabaseCount('user_audits', 2);
        $this->actingAs($user->fresh())->withSession(['auth_version' => 1])->get('/contracts/create')->assertRedirect('/login');
        $this->actingAs($user->fresh())->withSession(['auth_version' => 2])->get('/contracts/create')->assertForbidden();
    }

    public function test_user_needs_explicit_issuance_permission_for_creation_and_documents_but_can_consult(): void
    {
        $this->withoutVite();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'test', 'fund_id' => $fund, 'fund' => 'Fundo']);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'PasswordTest123']);
        $user->scopes()->attach($scope);
        $contract = Contract::create(['numero' => '001/26', 'tipo' => 'Fornecimento', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        $contract->refresh();
        $this->actingAs($user)->withSession(['auth_version' => 0, 'fund_id' => $fund]);
        $this->get('/contracts')->assertOk();
        $this->get('/contracts/'.$contract->id)->assertOk()->assertDontSee('Exportar resumo PDF');
        $this->get('/contracts/create')->assertForbidden();
        $this->post('/contracts', [])->assertForbidden();
        $this->get('/contracts/'.$contract->id.'/pdf')->assertForbidden();
        $this->get('/contracts/'.$contract->id.'/extract?version='.$contract->version)->assertForbidden();
        $this->get('/contracts/'.$contract->id.'/publication?version='.$contract->version)->assertForbidden();
        $this->get('/reports')->assertOk();
        try {
            app(ContractWriter::class)->save($user, []);
            $this->fail('The persistence adapter must reject unauthorized creation.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $user->catalog_permissions = ['supplier'];
        $user->save();
        $this->assertFalse($user->canIssueContracts());
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $this->actingAs($user->fresh())->get('/contracts/create')->assertOk();
        $this->get('/contracts/'.$contract->id)->assertOk()->assertSee('Exportar resumo PDF');
        $this->get('/contracts/'.$contract->id.'/extract?version=99999')->assertStatus(409);
        $user->scopes()->detach();
        $this->assertFalse((new ContractPolicy)->create($user->fresh()));
        $this->assertFalse((new ContractPolicy)->issue($user->fresh(), $contract));
    }
}
