<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Models\User;
use App\Support\ContractFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractBrowsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 9)->startOfDay());
    }

    private function user(bool $admin = true): User
    {
        return User::create(['name' => 'Pessoa fictícia', 'username' => 'TESTE', 'password' => 'SenhaFicticia123',
            'user_type' => $admin ? 'Administrador' : 'Usuario']);
    }

    private function contract(array $attributes = []): Contract
    {
        $id = Contract::withTrashed()->count() + 1;
        $run = ImportRun::create(['source_hash' => str_repeat('a', 64), 'status' => 'completed', 'started_at' => now()]);
        $record = LegacyRecord::create(['source_table' => 'TB_Contratos', 'legacy_id' => (string) $id,
            'source_hash' => hash('sha256', (string) $id), 'raw_payload' => [], 'import_run_id' => $run->id]);

        return Contract::create(array_replace(['legacy_id' => $id, 'legacy_record_id' => $record->id,
            'numero' => '019/26', 'processo' => 'PROCESSO-TESTE', 'fornecedor_nome_snapshot' => 'Fornecedor fictício',
            'fornecedor_documento_snapshot' => '11.222.333/0001-81', 'supplier_document_key' => '11222333000181',
            'fundo_nome_snapshot' => 'Fundo fictício', 'valor_total' => '1908.6300', 'exercicio' => 2026,
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-12-31',
            'ownership_resolved' => true, 'vigencia_em_revisao' => false, 'quality_issues' => []], $attributes));
    }

    private function catalog(string $table, string $name): int
    {
        return DB::table($table)->insertGetId(['legacy_id' => DB::table($table)->count() + 1,
            'name' => $name, 'name_key' => $name, 'raw_payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_guest_and_inactive_user_cannot_browse_contracts(): void
    {
        $contract = $this->contract();
        $this->get('/contracts')->assertRedirect('/login');
        $this->get('/contracts/'.$contract->id)->assertRedirect('/login');
        $user = $this->user();
        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user)->get('/contracts')->assertRedirect('/login');
    }

    public function test_admin_can_search_and_combine_filters_without_merging_duplicate_numbers(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $department = $this->catalog('contract_departments', 'Secretaria A');
        $one = $this->contract(['fund_id' => $fund, 'department_id' => $department]);
        $two = $this->contract(['fornecedor_nome_snapshot' => 'Outro fornecedor', 'exercicio' => 2025]);
        $this->actingAs($this->user())->get('/contracts?q=019%2F26')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 2);
        foreach (['PROCESSO-TESTE', 'Fornecedor fictício', '11222333000181', '11.222.333/0001-81'] as $search) {
            $this->get('/contracts?'.http_build_query(['q' => $search, 'fund' => $fund, 'department' => $department,
                'year' => 2026, 'validity' => 'vigente']))->assertOk()
                ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $one->id);
        }
        $this->get('/contracts?year=2025')->assertViewHas('contracts', fn ($rows) => $rows->first()->id === $two->id);
        $this->get('/contracts?validity=invalido')->assertSessionHasErrors('validity');
    }

    public function test_scope_limits_rows_filter_options_and_direct_urls(): void
    {
        $fundA = $this->catalog('contract_funds', 'Fundo autorizado');
        $fundB = $this->catalog('contract_funds', 'Fundo restrito');
        $departmentA = $this->catalog('contract_departments', 'Secretaria autorizada');
        $departmentB = $this->catalog('contract_departments', 'Secretaria restrita');
        $allowed = $this->contract(['fund_id' => $fundA, 'department_id' => $departmentA]);
        $otherFund = $this->contract(['fund_id' => $fundB, 'department_id' => $departmentB, 'exercicio' => 2020]);
        $otherDepartment = $this->contract(['fund_id' => $fundA, 'department_id' => $departmentB]);
        $unresolved = $this->contract(['fund_id' => $fundA, 'department_id' => $departmentA, 'ownership_resolved' => false]);
        $user = $this->user(false);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'a'), 'fund' => 'Fundo autorizado',
            'department' => 'Secretaria autorizada', 'fund_id' => $fundA, 'department_id' => $departmentA]);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->get('/contracts')->assertOk()->assertDontSee('Fundo restrito')->assertDontSee('Secretaria restrita')
            ->assertViewHas('years', fn ($years) => ! $years->contains(2020))
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $allowed->id);
        $this->get('/contracts/'.$allowed->id)->assertOk();
        foreach ([$otherFund, $otherDepartment, $unresolved] as $contract) {
            $this->get('/contracts/'.$contract->id)->assertForbidden();
        }
        $this->get('/contracts?fund='.$fundB)->assertViewHas('contracts', fn ($rows) => $rows->total() === 0);
        $user->scopes()->detach();
        $this->get('/contracts')->assertViewHas('contracts', fn ($rows) => $rows->total() === 0);
        $this->get('/contracts/'.$allowed->id)->assertForbidden();
    }

    public function test_detail_renders_all_fields_masks_personal_documents_and_escapes_source_text(): void
    {
        $fund = $this->catalog('contract_funds', 'Fundo A');
        $contract = $this->contract(['fund_id' => $fund, 'objeto' => '<script>alert(1)</script>',
            'cpf_gestor' => '12345678901', 'fornecedor_documento_snapshot' => '12345678901',
            'vigencia_fim_atual' => null, 'ownership_resolved' => false,
            'quality_issues' => [['field' => 'Final_Vig_Atualiz', 'code' => 'zero_date', 'message' => 'Data inválida na origem.']]]);
        $admin = $this->user();
        $response = $this->actingAs($admin)->get('/contracts/'.$contract->id)->assertOk()
            ->assertSee('R$ 1.908,63')->assertSee('Não informado')->assertSee('Data inválida na origem.')
            ->assertSee('restrita a administradores')->assertSee('12345678901')->assertDontSee('<script>alert(1)</script>', false);
        foreach (array_keys(ContractFields::MAP) as $source) {
            $response->assertSee(ContractFields::label($source));
        }
        $contract->update(['ownership_resolved' => true]);
        $admin->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'all-fund'), 'fund' => 'Fundo A', 'fund_id' => $fund]);
        $admin->scopes()->attach($scope);
        $this->get('/contracts/'.$contract->id)->assertOk()->assertDontSee('12345678901')->assertSee('•••8901');
    }

    public function test_pagination_preserves_filters_and_excludes_soft_deleted_contracts(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->contract();
        }
        $deleted = $this->contract();
        $deleted->delete();
        $this->actingAs($this->user())->get('/contracts?q=019&year=2026')->assertOk()
            ->assertViewHas('contracts', fn ($rows) => $rows->total() === 22 && $rows->count() === 20)
            ->assertSee('q=019', false)->assertSee('year=2026', false);
        $this->get('/contracts?q=019&year=2026&page=2')->assertViewHas('contracts', fn ($rows) => $rows->count() === 2);
        $this->get('/contracts/'.$deleted->id)->assertNotFound();
    }

    public function test_validity_filters_match_display_with_missing_dates_and_amendment_review(): void
    {
        $cases = [
            'vigente' => [],
            'a_iniciar' => ['vigencia_inicio' => '2026-11-01'],
            'vencido' => ['vigencia_fim_original' => '2026-10-08'],
            'indeterminado' => ['vigencia_inicio' => null],
            'em_revisao' => ['vigencia_em_revisao' => true],
        ];
        $this->actingAs($this->user());
        foreach ($cases as $status => $attributes) {
            $contract = $this->contract($attributes);
            $this->assertSame($status, $contract->validity);
        }
        foreach (array_keys($cases) as $status) {
            $this->get('/contracts?validity='.$status)->assertOk()
                ->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->validity === $status);
        }
    }
}
