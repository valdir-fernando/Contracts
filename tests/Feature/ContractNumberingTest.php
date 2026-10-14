<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\ContractWriter;
use App\Services\Legacy\ContractCounterImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ContractNumberingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fund;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 13)->startOfDay());
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->fund = $this->fund('Fundo A');
        DB::table('suppliers')->insert(['name' => 'Fornecedor', 'name_key' => 'FORNECEDOR', 'document' => '11222333000181', 'document_key' => '11222333000181', 'raw_payload' => '{}']);
        $this->actingAs($this->admin);
        $this->withSession(['fund_id' => $this->fund]);
    }

    private function fund(string $name): int
    {
        return DB::table('contract_funds')->insertGetId(['name' => $name, 'name_key' => strtoupper($name), 'raw_payload' => '{}']);
    }

    private function input(array $values = []): array
    {
        return array_replace(['fund_id' => $this->fund, 'processo' => 'PROC', 'fornecedor_nome_snapshot' => 'Fornecedor',
            'fornecedor_documento_snapshot' => '11222333000181', 'objeto' => 'Objeto sintético', 'tipo' => 'Serviço',
            'modalidade' => 'Pregão', 'n_licit' => '001/2026', 'valor_total' => '100.0000', 'data_contrato' => '2026-10-13', 'vigencia_inicio' => '2026-10-13',
            'vigencia_fim_original' => '2027-10-13'], $values);
    }

    private function save(array $values = []): Contract
    {
        return app(ContractWriter::class)->save($this->admin, $this->input($values));
    }

    public function test_number_is_assigned_at_save_and_client_numbers_cannot_control_it(): void
    {
        $this->get('/contracts/create')->assertOk()->assertSee('Gerado ao salvar')
            ->assertDontSee('name="numero"', false)->assertDontSee('name="cont_inteiro"', false);
        $first = $this->save(['numero' => '999/99', 'cont_inteiro' => '999']);
        $second = $this->save();
        $this->assertSame('001/26', $first->numero);
        $this->assertSame('1', $first->cont_inteiro);
        $this->assertSame('002/26', $second->numero);
        $this->assertSame(2026, $first->number_year);
        $this->assertDatabaseHas('tb_N_Contrato', ['fund_id' => $this->fund, 'ANO' => 2026, 'NUMERO' => 2]);
        $audit = ContractAudit::where('contract_id', $first->id)->firstOrFail();
        $this->assertSame('001/26', $audit->changes['numero']['after']);
        $this->assertSame('1', $audit->changes['cont_inteiro']['after']);
    }

    public function test_funds_and_years_are_independent_and_four_digits_continue_after_999(): void
    {
        DB::table('tb_N_Contrato')->insert(['fund_id' => $this->fund, 'ANO' => 2026, 'FUNDO' => 'Fundo A', 'NUMERO' => 999]);
        $this->assertSame('1000/26', $this->save()->numero);
        $fundB = $this->fund('Fundo B');
        $this->assertSame('001/26', $this->save(['fund_id' => $fundB])->numero);
        $this->travelTo(now()->setDate(2027, 1, 1));
        $contract = $this->save(['data_contrato' => '2026-12-31']);
        $this->assertSame('001/27', $contract->numero);
        $this->assertSame(2027, $contract->exercicio);
        $this->assertDatabaseHas('tb_N_Contrato', ['fund_id' => $this->fund, 'ANO' => 2026, 'NUMERO' => 1000]);
        $this->assertDatabaseHas('tb_N_Contrato', ['fund_id' => $this->fund, 'ANO' => 2027, 'NUMERO' => 1]);
    }

    public function test_historical_and_deleted_numbers_are_never_reused_and_history_is_not_changed(): void
    {
        $old = Contract::create(['fund_id' => $this->fund, 'numero' => " 0012 / 2026\t", 'cont_inteiro' => 'Texto histórico', 'quality_issues' => []]);
        $deleted = Contract::create(['fund_id' => $this->fund, 'numero' => '010/26', 'quality_issues' => []]);
        $deleted->delete();
        $this->assertSame('013/26', $this->save()->numero);
        $this->assertSame('Texto histórico', $old->fresh()->cont_inteiro);
        $this->assertSame(" 0012 / 2026\t", $old->fresh()->numero);
        $this->assertNull($old->fresh()->number_sequence);
        $saved = $this->save();
        $saved->delete();
        $this->assertSame('015/26', $this->save()->numero);
    }

    public function test_invalid_input_or_destination_does_not_consume_a_number(): void
    {
        $this->post('/contracts', $this->input(['objeto' => '']))->assertSessionHasErrors('objeto');
        $this->assertDatabaseCount('tb_N_Contrato', 0);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $scope = AccessScope::create(['scope_key' => 'numbering', 'fund' => 'Fundo A', 'fund_id' => $this->fund]);
        $user->scopes()->attach($scope);
        Contract::create(['fund_id' => $this->fund, 'supplier_id' => DB::table('suppliers')->where('document_key', '11222333000181')->value('id'), 'ownership_resolved' => true, 'quality_issues' => []]);
        $fundB = $this->fund('Fundo B');
        $this->actingAs($user)->post('/contracts', $this->input(['fund_id' => $fundB]))->assertForbidden();
        $this->assertDatabaseCount('tb_N_Contrato', 0);
        $this->post('/contracts', $this->input(['number_year' => 1999, 'number_sequence' => 800]))->assertSessionHasErrors(['number_year', 'number_sequence']);
        $this->assertDatabaseCount('tb_N_Contrato', 0);
    }

    public function test_same_two_digit_suffix_from_another_century_does_not_share_counter(): void
    {
        // Keep persistence timestamps in their supported range; only the exercise is historical.
        Contract::create(['fund_id' => $this->fund, 'numero' => '1000/26', 'exercicio' => 1926, 'quality_issues' => []]);
        $contract = $this->save();
        $this->assertSame('001/26', $contract->numero);
        $this->assertSame(2026, $contract->exercicio);
        $this->assertSame(2026, $contract->number_year);
    }

    public function test_audit_failure_rolls_back_both_new_and_existing_counters(): void
    {
        foreach ([false, true] as $existing) {
            if ($existing) {
                DB::table('tb_N_Contrato')->insert(['fund_id' => $this->fund, 'ANO' => 2026, 'FUNDO' => 'Fundo A', 'NUMERO' => 10]);
            }
            ContractAudit::creating(fn () => throw new RuntimeException('Simulated audit failure'));
            try {
                $this->save();
                $this->fail('Falha de auditoria deve reverter o contrato e o contador.');
            } catch (RuntimeException $error) {
                $this->assertSame('Simulated audit failure', $error->getMessage());
            } finally {
                ContractAudit::flushEventListeners();
            }
            $this->assertDatabaseCount('contracts', 0);
            $this->assertDatabaseCount('contract_audits', 0);
            $this->assertDatabaseCount('tb_N_Contrato', $existing ? 1 : 0);
            if ($existing) {
                $this->assertDatabaseHas('tb_N_Contrato', ['fund_id' => $this->fund, 'NUMERO' => 10]);
            }
        }
        $this->assertSame('011/26', $this->save()->numero);
    }

    public function test_generated_number_and_fund_remain_stable_on_edit_and_database_enforces_uniqueness(): void
    {
        $contract = $this->save();
        foreach (['numero' => '999/26', 'cont_inteiro' => '999'] as $field => $value) {
            $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Tentativa de renumerar', $field => $value])->assertSessionHasErrors($field);
        }
        $fundB = $this->fund('Fundo B');
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Tentativa de mover número', 'fund_id' => $fundB])->assertSessionHasErrors('fund_id');
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Correção do objeto', 'objeto' => 'Corrigido'])->assertRedirect();
        $this->assertSame('001/26', $contract->fresh()->numero);
        $this->assertSame(1, $contract->fresh()->number_sequence);
        $this->assertDatabaseHas('tb_N_Contrato', ['fund_id' => $this->fund, 'NUMERO' => 1]);
        $this->expectException(QueryException::class);
        DB::table('contracts')->insert(['fund_id' => $this->fund, 'number_year' => 2026, 'number_sequence' => 1, 'quality_issues' => '[]']);
    }

    public function test_legacy_counter_import_requires_explicit_year_preserves_source_and_never_decreases(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'counter-test-');
        try {
            file_put_contents($path, "INSERT INTO `tb_N_Contrato` (`ID`, `FUNDO`, `NUMERO`) VALUES (1, 'Fundo A', '58');");
            $importer = app(ContractCounterImporter::class);
            $this->assertSame(58, $importer->import($path, 2026, true)[0]['last_number']);
            $this->assertDatabaseCount('tb_N_Contrato', 0);
            $this->assertDatabaseCount('import_runs', 0);
            $this->assertSame(58, $importer->import($path, 2026)[0]['last_number']);
            $this->assertDatabaseHas('legacy_records', ['source_table' => 'tb_N_Contrato', 'legacy_id' => '1']);
            $this->assertSame('059/26', $this->save()->numero);
            $this->assertSame(59, $importer->import($path, 2026)[0]['last_number']);
            $this->assertDatabaseCount('tb_N_Contrato', 1);
            $this->assertDatabaseCount('legacy_records', 1);
            $this->travelTo(now()->setDate(2027, 1, 1));
            $this->assertSame('001/27', $this->save()->numero);
            $this->assertDatabaseHas('tb_N_Contrato', ['fund_id' => $this->fund, 'ANO' => 2026, 'NUMERO' => 59]);
        } finally {
            unlink($path);
        }
    }

    public function test_legacy_counter_below_used_numbers_is_reconciled_and_ambiguous_fund_is_refused(): void
    {
        Contract::create(['fund_id' => $this->fund, 'numero' => '070/26', 'quality_issues' => []]);
        $path = tempnam(sys_get_temp_dir(), 'counter-test-');
        try {
            file_put_contents($path, "INSERT INTO `tb_N_Contrato` (`ID`, `FUNDO`, `NUMERO`) VALUES (1, 'Fundo A', '58');");
            $this->assertSame(70, app(ContractCounterImporter::class)->import($path, 2026)[0]['last_number']);
            $this->assertSame('071/26', $this->save()->numero);
            $this->fund('Fundo A');
            $this->expectException(RuntimeException::class);
            app(ContractCounterImporter::class)->import($path, 2026);
        } finally {
            unlink($path);
        }
    }
}
