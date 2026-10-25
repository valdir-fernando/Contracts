<?php

namespace Tests\Feature;

use App\Infrastructure\Contracts\ImportHistoricalAmendments;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\ContractHistoryRecord;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HistoricalAmendmentImportTest extends TestCase
{
    use RefreshDatabase;

    private function history(?Contract $contract, array $raw = [], string $status = 'matched'): ContractHistoryRecord
    {
        $run = ImportRun::create(['source_hash' => str_repeat('a', 64), 'status' => 'completed', 'started_at' => now()]);
        $raw += ['ID' => (string) (LegacyRecord::count() + 1), 'N_ADT' => '001', 'Data_ADT' => '2026-01-15', 'Proc_ADT' => 'P-ADT',
            'Tipo_ADT' => 'Prorrogação', 'Motivo_ADT' => 'Motivo histórico', 'VL_ADT' => '1234.5678',
            'Fornecedor' => 'Fornecedor histórico', 'CPF_Socio' => '12345678909', 'Incio_Servico' => '0000-00-00'];
        $source = LegacyRecord::create(['source_table' => 'tb_Aditivos', 'legacy_id' => $raw['ID'],
            'source_hash' => hash('sha256', json_encode($raw)), 'raw_payload' => $raw, 'import_run_id' => $run->id]);

        return ContractHistoryRecord::create(['kind' => 'amendment', 'legacy_id' => $raw['ID'], 'legacy_record_id' => $source->id,
            'contract_id' => $contract?->id, 'match_status' => $status, 'candidate_ids' => [], 'contract_number' => '001/26', 'quality_issues' => []]);
    }

    private function contract(): Contract
    {
        return Contract::create(['numero' => '001/26', 'processo' => 'P-1', 'ownership_resolved' => true, 'quality_issues' => [],
            'valor_total' => '987.6543', 'valor_acumulado' => '999.0000', 'vigencia_fim_atual' => '2027-01-01', 'vigencia_em_revisao' => false]);
    }

    public function test_import_preserves_origin_exact_money_snapshots_and_contract_values_and_is_idempotent(): void
    {
        $contract = $this->contract();
        $before = $contract->fresh()->getAttributes();
        $history = $this->history($contract);
        $raw = $history->source->raw_payload;
        $counts = app(ImportHistoricalAmendments::class)->run(false);
        $this->assertSame(1, $counts['imported']);
        $amendment = ContractAmendment::firstOrFail();
        $this->assertSame('001', $amendment->number);
        $this->assertSame('1234.5678', $amendment->amount);
        $this->assertSame('importado', $amendment->status);
        $this->assertSame('Fornecedor histórico', $amendment->payload['Fornecedor']);
        $this->assertNull($amendment->payload['Incio_Servico']);
        $this->assertNull($amendment->created_by);
        $this->assertSame($history->legacy_record_id, $amendment->legacy_record_id);
        $this->assertSame($raw, $history->source->fresh()->raw_payload);
        foreach (['valor_total', 'valor_acumulado', 'vigencia_fim_atual', 'vigencia_em_revisao'] as $field) {
            $this->assertSame($before[$field], $contract->fresh()->getRawOriginal($field));
        }
        $this->assertSame($before['version'] + 1, $contract->fresh()->version);
        $audit = ContractAudit::firstOrFail();
        $this->assertNull($audit->actor_id);
        $this->assertNotNull($audit->import_run_id);
        $this->assertSame('amendments_imported', $audit->action);
        $this->assertSame(1, app(ImportHistoricalAmendments::class)->run(false)['skipped']);
        $this->assertSame(1, ContractAmendment::count());
        $this->assertSame(1, ContractAudit::count());
        $this->assertSame(1, ContractHistoryRecord::count());
        $this->assertDatabaseHas('import_issues', ['code' => 'amendment_normalization', 'field' => 'Incio_Servico']);
    }

    public function test_simulation_has_no_writes_and_invalid_or_unmatched_rows_are_not_guessed(): void
    {
        $contract = $this->contract();
        $this->history($contract);
        $this->history(null, ['N_ADT' => '002'], 'unmatched');
        $this->history($contract, ['N_ADT' => '003', 'Data_ADT' => '0000-00-00']);
        $runs = ImportRun::count();
        $counts = app(ImportHistoricalAmendments::class)->run();
        $this->assertSame(1, $counts['imported']);
        $this->assertSame(1, $counts['unmatched']);
        $this->assertSame(1, $counts['invalid']);
        $this->assertSame(0, ContractAmendment::count());
        $this->assertSame(0, ContractAudit::count());
        $this->assertSame($runs, ImportRun::count());
        $this->assertDatabaseCount('import_issues', 0);
        $this->assertSame(1, $contract->fresh()->version);
    }

    public function test_reserved_numbers_including_soft_deleted_records_are_preserved_as_conflicts(): void
    {
        $contract = $this->contract();
        $this->history($contract);
        $native = ContractAmendment::create(['contract_id' => $contract->id, 'number' => '001', 'event_date' => '2026-02-01', 'payload' => ['original' => true]]);
        $native->delete();
        $counts = app(ImportHistoricalAmendments::class)->run(false);
        $this->assertSame(1, $counts['conflict']);
        $this->assertSame(0, $counts['imported']);
        $this->assertSame(['original' => true], $native->fresh()->payload);
        $this->assertTrue($native->fresh()->trashed());
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_audit_failure_rolls_back_import_and_contract_version(): void
    {
        $contract = $this->contract();
        $this->history($contract);
        ContractAudit::creating(function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(ImportHistoricalAmendments::class)->run(false);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertDatabaseCount('tb_Aditivos', 0);
        $this->assertSame(1, $contract->fresh()->version);
        $this->assertDatabaseCount('import_runs', 1);
    }

    public function test_duplicate_historical_numbers_are_both_left_pending_without_selecting_one(): void
    {
        $contract = $this->contract();
        $this->history($contract, ['Data_ADT' => '2026-04-28']);
        $this->history($contract, ['Data_ADT' => '2026-04-30']);
        $counts = app(ImportHistoricalAmendments::class)->run(false);
        $this->assertSame(2, $counts['conflict']);
        $this->assertSame(0, $counts['imported']);
        $this->assertDatabaseCount('tb_Aditivos', 0);
        $this->assertSame(1, $contract->fresh()->version);
    }

    public function test_imported_amendment_is_visible_and_masked_but_cannot_be_edited_by_regular_users(): void
    {
        $this->withoutVite();
        $contract = $this->contract();
        $this->history($contract);
        app(ImportHistoricalAmendments::class)->run(false);
        $amendment = ContractAmendment::firstOrFail();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'Test123456', 'user_type' => 'Administrador']);
        $this->actingAs($admin)->get(route('contracts.amendments.index', $contract))->assertOk()->assertSee('001')->assertSee('R$ 999,00');
        $user = User::create(['name' => 'User', 'username' => 'USER', 'password' => 'Test123456', 'user_type' => 'Usuario']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $contract->update(['fund_id' => $fund]);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'import-scope'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $user->scopes()->attach($scope);
        $this->actingAs($user)->get(route('contracts.amendments.show', [$contract, $amendment]))
            ->assertOk()->assertDontSee('12345678909')->assertDontSee('Editar aditivo');
        $this->actingAs($user)->get(route('contracts.amendments.edit', [$contract, $amendment]))->assertForbidden();
        $this->put(route('contracts.amendments.update', [$contract, $amendment]), ['contract_version' => $contract->fresh()->version,
            'version' => 1, 'reason' => 'Tentativa de alterar importado', 'fields' => ['Motivo_ADT' => 'Alteração']])->assertForbidden();
        // display() applies the same personal-data projection used by the existing details view.
        $this->assertNotSame('12345678909', $amendment->display('CPF_Socio', $user));
    }
}
