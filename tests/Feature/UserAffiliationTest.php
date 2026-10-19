<?php

namespace Tests\Feature;

use App\Infrastructure\Security\UserAffiliation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserAffiliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_sets_municipality_and_fund_selection_clears_department(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword123', 'user_type' => 'Administrador']);
        $municipality = DB::table('contract_funds')->insertGetId(['name' => 'MUNICÍPIO DE NIQUELÂNDIA', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{}']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Saúde', 'name_key' => 'FUNDO SAUDE', 'raw_payload' => '{}']);
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria Educação', 'name_key' => 'SECRETARIA EDUCACAO', 'raw_payload' => '{}']);
        $options = (new UserAffiliation)->options();
        $this->assertArrayNotHasKey('fund:'.$municipality, $options);
        $this->assertSame('Fundo Saúde', $options['fund:'.$fund]);
        $this->assertSame('Secretaria Educação', $options['department:'.$department]);
        $data = ['name' => 'Pessoa', 'username' => 'PESSOA', 'user_type' => 'Usuario', 'is_active' => '1', 'affiliations_present' => '1',
            'affiliations' => ['department:'.$department], 'password' => 'TestPassword123', 'password_confirmation' => 'TestPassword123'];
        $this->actingAs($admin)->withSession(['auth_version' => 0])->post('/users', $data)->assertRedirect('/users');
        $user = User::where('username', 'PESSOA')->firstOrFail();
        $this->assertSame('MUNICÍPIO DE NIQUELÂNDIA', $user->fund);
        $this->assertSame('Secretaria Educação', $user->department);
        $this->assertSame($municipality, $user->scopes->sole()->fund_id);
        $this->assertDatabaseHas('user_audits', ['user_id' => $user->id, 'action' => 'created']);
        unset($data['password'], $data['password_confirmation']);
        $data['version'] = $user->session_version;
        $data['affiliations'] = ['fund:'.$fund];
        $this->put('/users/'.$user->id, $data)->assertRedirect('/users');
        $this->assertSame('Fundo Saúde', $user->fresh()->fund);
        $this->assertNull($user->fresh()->department);
        $this->assertNull($user->fresh()->scopes->sole()->department_id);
        $data['username'] = 'INVALID';
        $data['password'] = $data['password_confirmation'] = 'TestPassword123';
        $data['affiliations'] = ['fund:'.$municipality];
        $this->post('/users', $data)->assertSessionHasErrors('affiliations');
        $this->assertDatabaseMissing('users', ['username' => 'INVALID']);
        $data['affiliations'] = ['fund:'.$fund, 'department:'.$department];
        $this->post('/users', $data)->assertSessionHasErrors('affiliations');
        $this->assertDatabaseMissing('users', ['username' => 'INVALID']);
        $data['affiliations'] = ['department:999999'];
        $this->post('/users', $data)->assertSessionHasErrors('affiliations');
        $this->assertDatabaseMissing('users', ['username' => 'INVALID']);
        $data['user_type'] = 'Administrador';
        $data['affiliations'] = [''];
        unset($data['is_active']);
        $this->post('/users', $data)->assertSessionHasErrors(['affiliations', 'is_active']);
        $this->assertDatabaseMissing('users', ['username' => 'INVALID']);
    }
}
