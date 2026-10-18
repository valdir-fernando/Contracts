<?php

namespace Tests\Feature;

use App\Infrastructure\ContractStewards\ContractStewardRegistration;
use App\Infrastructure\ContractStewards\CopyFundManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StewardAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private int $municipality;

    private int $fund;

    private int $education;

    private int $works;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actingAs(User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'Synthetic123', 'user_type' => 'Administrador']));
        $this->municipality = DB::table('contract_funds')->insertGetId(['name' => 'MUNICIPIO DE NIQUELÂNDIA', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => '{}']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Saúde', 'name_key' => 'FUNDO SAUDE', 'raw_payload' => '{}']);
        $this->education = DB::table('contract_departments')->insertGetId(['name' => 'Educação', 'name_key' => 'EDUCACAO', 'raw_payload' => '{}']);
        $this->works = DB::table('contract_departments')->insertGetId(['name' => 'Obras', 'name_key' => 'OBRAS', 'raw_payload' => '{}']);
    }

    private function input(?int $department = null, ?int $fund = null): array
    {
        return ['Nome_Gestor' => 'Gestor de teste', 'CPF_Gestor' => '52998224725',
            'fund_id' => $fund ?? $this->municipality, 'department_id' => $department];
    }

    private function create(?int $department = null, ?int $fund = null): int
    {
        $this->post('/contract-stewards', $this->input($department, $fund))->assertRedirect()->assertSessionHasNoErrors();

        return DB::table('contract_stewards')->max('id');
    }

    private function revision(int $id): string
    {
        return app(ContractStewardRegistration::class)->revision(DB::table('contract_stewards')->find($id));
    }

    public function test_all_departments_blocks_any_additional_municipal_manager_and_delete_releases_scope(): void
    {
        $id = $this->create();
        foreach ([null, $this->education, $this->works] as $department) {
            $this->post('/contract-stewards', $this->input($department))->assertSessionHasErrors('department_id');
        }
        $this->assertDatabaseCount('contract_stewards', 1);
        $this->assertDatabaseCount('tb_Gestor_Cont', 1);
        $this->assertDatabaseCount('activity_events', 1);
        $this->get('/catalogs/list/contract-steward')->assertOk()->assertSee('Todas as Secretarias')->assertDontSee('Sem secretaria');
        $this->delete('/contract-stewards/'.$id, ['revision' => $this->revision($id), 'confirmation' => 1])->assertRedirect();
        $this->create($this->education);
        $this->create($this->works);
    }

    public function test_each_department_has_one_manager_and_blocks_all_departments_choice(): void
    {
        $education = $this->create($this->education);
        $works = $this->create($this->works);
        $this->post('/contract-stewards', $this->input($this->education))->assertSessionHasErrors('department_id');
        $this->post('/contract-stewards', $this->input())->assertSessionHasErrors('department_id');
        $this->put('/contract-stewards/'.$works, $this->input($this->education) + ['revision' => $this->revision($works)])
            ->assertSessionHasErrors('department_id');
        $this->put('/contract-stewards/'.$education, $this->input() + ['revision' => $this->revision($education)])
            ->assertSessionHasErrors('department_id');
        $this->assertDatabaseCount('activity_events', 2);
        $this->assertSame('OBRAS', DB::table('contract_stewards')->find($works)->department_name_key);
    }

    public function test_moving_all_departments_manager_releases_other_departments_and_omitted_department_is_preserved(): void
    {
        $id = $this->create();
        $this->put('/contract-stewards/'.$id, $this->input($this->education) + ['revision' => $this->revision($id)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->create($this->works);
        $partial = $this->input($this->education);
        unset($partial['department_id']);
        $this->put('/contract-stewards/'.$id, $partial + ['revision' => $this->revision($id)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('EDUCACAO', DB::table('contract_stewards')->find($id)->department_name_key);
        $this->assertSame('Educação', DB::table('tb_Gestor_Cont')->where('contract_steward_id', $id)->value('Secretaria'));
    }

    public function test_other_funds_allow_one_manager_without_department_and_transfer_releases_old_fund(): void
    {
        $id = $this->create(null, $this->fund);
        $this->post('/contract-stewards', $this->input(null, $this->fund))->assertSessionHasErrors('fund_id');
        $this->post('/contract-stewards', $this->input($this->education, $this->fund))->assertSessionHasErrors('department_id');
        $this->put('/contract-stewards/'.$id, $this->input($this->education) + ['revision' => $this->revision($id)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->create(null, $this->fund);
        $this->create($this->works);
    }

    public function test_inactive_non_deleted_record_still_occupies_its_scope_and_another_admin_cannot_bypass_it(): void
    {
        $id = $this->create($this->education);
        $this->put('/contract-stewards/'.$id, $this->input($this->education) + ['Situacao' => 'Inativo', 'revision' => $this->revision($id)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $other = User::create(['name' => 'Outro', 'username' => 'OUTRO', 'password' => 'Synthetic123', 'user_type' => 'Administrador']);
        $this->actingAs($other)->post('/contract-stewards', $this->input($this->education))->assertSessionHasErrors('department_id');
    }

    public function test_cli_copy_cannot_create_all_departments_manager_when_a_department_is_occupied(): void
    {
        $this->create($this->education);
        DB::table('tb_Gestor')->insert(['name' => 'Outra pessoa', 'name_key' => 'OUTRA PESSOA', 'fund_name_key' => 'MUNICIPIO DE NIQUELANDIA',
            'raw_payload' => '{"CPF_Gestor":"11144477735","Nome_Gestor":"Outra pessoa"}']);
        try {
            app(CopyFundManager::class)->copy('Outra pessoa', 'MUNICIPIO DE NIQUELÂNDIA');
            $this->fail('CLI must respect occupied scopes.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('department_id', $error->errors());
        }
        $this->assertDatabaseCount('contract_stewards', 1);
        $this->assertDatabaseCount('import_runs', 0);
    }
}
