<?php

namespace Tests\Feature;

use App\Infrastructure\Contracts\ReconcileContractAccumulatedValues;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\ContractHistoryRecord;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class ReconcileContractAccumulatedValuesTest extends TestCase
{
    use RefreshDatabase;

    private function contract(array $values = []): Contract
    {
        return Contract::create($values + ['numero' => '001/26', 'valor_total' => '1000.0001',
            'valor_acumulado' => '9999.0000', 'version' => 9, 'quality_issues' => ['preservar' => true],
            'vigencia_fim_atual' => '2027-06-30', 'objeto' => 'Contrato preservado']);
    }

    private function amendment(Contract $contract, ?string $amount, array $values = []): ContractAmendment
    {
        return ContractAmendment::create($values + ['contract_id' => $contract->id,
            'number' => (string) (ContractAmendment::withTrashed()->count() + 1), 'amount' => $amount,
            'status' => 'importado', 'event_date' => '2026-10-28', 'payload' => ['VL_ADT' => $amount]]);
    }

    public function test_simulation_adds_original_once_and_signed_amendments_with_four_decimal_places_without_writes(): void
    {
        $contract = $this->contract();
        $this->amendment($contract, '100.0002');
        $this->amendment($contract, '-20.0001');
        $this->amendment($contract, '0.0000');
        $before = $contract->fresh()->getAttributes();
        $result = app(ReconcileContractAccumulatedValues::class)->run();
        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['divergent']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('80.0001', $result['differences'][0]['amendments_sum']);
        $this->assertSame('1080.0002', $result['differences'][0]['after']);
        $this->assertSame($before, $contract->fresh()->getAttributes());
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_apply_is_audited_idempotent_and_preserves_every_other_business_field_and_amendments(): void
    {
        $contract = $this->contract();
        $amendment = $this->amendment($contract, '0.0002', ['status' => 'registrado']);
        $correct = $this->contract(['valor_total' => '20.0000', 'valor_acumulado' => '25.0000']);
        $this->amendment($correct, '5.0000');
        $without = $this->contract();
        $deleted = $this->contract(['deleted_at' => now()]);
        $this->amendment($deleted, '300.0000');
        $this->amendment($contract, '400.0000', ['deleted_at' => now()]);
        $before = $contract->fresh()->getAttributes();
        $amendmentBefore = $amendment->fresh()->getAttributes();
        $untouched = [$correct->fresh()->getAttributes(), $without->fresh()->getAttributes(), $deleted->fresh()->getAttributes()];
        $result = app(ReconcileContractAccumulatedValues::class)->run(false);
        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['ok']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('1000.0003', $contract->fresh()->valor_acumulado);
        $this->assertSame(10, $contract->fresh()->version);
        $after = $contract->fresh()->getAttributes();
        foreach (['valor_acumulado', 'version', 'updated_by', 'updated_at'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertSame($before, $after);
        $this->assertSame($amendmentBefore, $amendment->fresh()->getAttributes());
        $this->assertSame($untouched, [$correct->fresh()->getAttributes(), $without->fresh()->getAttributes(), $deleted->fresh()->getAttributes()]);
        $audit = ContractAudit::sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame('accumulated_value_reconciled', $audit->action);
        $this->assertSame(['before' => '9999.0000', 'after' => '1000.0003'], $audit->changes['valor_acumulado']);
        $this->assertSame($amendment->id, $audit->changes['reconciliation']['after']['amendments'][0]['id']);
        $this->assertSame(0, app(ReconcileContractAccumulatedValues::class)->run(false)['updated']);
        $this->assertDatabaseCount('contract_audits', 1);
    }

    public function test_missing_values_and_drafts_remain_pending_but_explicit_zero_and_null_accumulated_are_supported(): void
    {
        $missingOriginal = $this->contract(['valor_total' => null]);
        $this->amendment($missingOriginal, '12.0000');
        $missingAmount = $this->contract();
        $this->amendment($missingAmount, null);
        $draft = $this->contract();
        $this->amendment($draft, '15.0000', ['status' => 'rascunho']);
        $zero = $this->contract(['valor_total' => '0.0000', 'valor_acumulado' => null]);
        $this->amendment($zero, '0.0000');
        $result = app(ReconcileContractAccumulatedValues::class)->run(false);
        $this->assertSame(3, $result['pending']);
        $this->assertSame(['missing_original_value', 'missing_amendment_value', 'unconfirmed_amendment'], array_column($result['issues'], 'reason'));
        $this->assertSame(1, $result['updated']);
        $this->assertSame('0.0000', $zero->fresh()->valor_acumulado);
        foreach ([$missingOriginal, $missingAmount, $draft] as $unchanged) {
            $this->assertSame('9999.0000', $unchanged->fresh()->valor_acumulado);
            $this->assertSame(9, $unchanged->fresh()->version);
        }
    }

    public function test_unimported_history_prevents_replacing_accumulated_with_a_partial_sum(): void
    {
        $contract = $this->contract();
        $this->amendment($contract, '10.0000');
        $run = ImportRun::create(['source_hash' => str_repeat('a', 64), 'status' => 'completed', 'started_at' => now()]);
        $source = LegacyRecord::create(['source_table' => 'tb_Aditivos', 'legacy_id' => '777',
            'source_hash' => str_repeat('b', 64), 'raw_payload' => [], 'import_run_id' => $run->id]);
        ContractHistoryRecord::create(['kind' => 'amendment', 'legacy_id' => '777', 'legacy_record_id' => $source->id,
            'contract_id' => $contract->id, 'match_status' => 'matched', 'candidate_ids' => [], 'quality_issues' => []]);
        $result = app(ReconcileContractAccumulatedValues::class)->run(false);
        $this->assertSame(1, $result['pending']);
        $this->assertSame('unimported_amendments', $result['issues'][0]['reason']);
        $this->assertSame('9999.0000', $contract->fresh()->valor_acumulado);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_out_of_range_total_is_reported_without_writes(): void
    {
        $contract = $this->contract(['valor_total' => '90000000000000.0000']);
        $this->amendment($contract, '90000000000000.0000');
        $result = app(ReconcileContractAccumulatedValues::class)->run(false);
        $this->assertSame(1, $result['pending']);
        $this->assertSame('amount_out_of_range', $result['issues'][0]['reason']);
        $this->assertSame('9999.0000', $contract->fresh()->valor_acumulado);
    }

    public function test_audit_failure_rolls_back_all_contracts_and_audits(): void
    {
        $first = $this->contract();
        $second = $this->contract();
        $this->amendment($first, '10.0000');
        $this->amendment($second, '20.0000');
        ContractAudit::creating(function ($audit) use ($second) {
            if ($audit->contract_id === $second->id) {
                throw new RuntimeException('Audit unavailable');
            }
        });
        try {
            app(ReconcileContractAccumulatedValues::class)->run(false);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            foreach ([$first, $second] as $contract) {
                $this->assertSame('9999.0000', $contract->fresh()->valor_acumulado);
                $this->assertSame(9, $contract->fresh()->version);
            }
            $this->assertDatabaseCount('contract_audits', 0);
        } finally {
            ContractAudit::flushEventListeners();
        }
    }

    public function test_command_defaults_to_simulation_and_refuses_competing_maintenance(): void
    {
        $contract = $this->contract();
        $this->amendment($contract, '1.0000');
        $this->artisan('contracts:reconcile-accumulated-values', ['--json' => true])->assertSuccessful();
        $this->assertSame('9999.0000', $contract->fresh()->valor_acumulado);
        $lock = Cache::lock('contracts-reference-import', 1800);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('contracts:reconcile-accumulated-values', ['--apply' => true])->assertFailed();
        } finally {
            $lock->release();
        }
        $this->artisan('contracts:reconcile-accumulated-values', ['--apply' => true, '--json' => true])->assertSuccessful();
        $this->assertSame('1001.0001', $contract->fresh()->valor_acumulado);
    }
}
