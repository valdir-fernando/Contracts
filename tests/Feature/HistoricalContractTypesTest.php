<?php

namespace Tests\Feature;

use App\Infrastructure\Contracts\CorrectHistoricalContractTypes;
use App\Models\Contract;
use App\Models\ContractAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use RuntimeException;
use Tests\TestCase;

class HistoricalContractTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_correction_is_exact_audited_idempotent_and_preserves_deleted_records(): void
    {
        $contract = Contract::create(['quality_issues' => [], 'tipo' => 'Servicos Diversos', 'version' => 4, 'objeto' => 'Preservar']);
        $unknown = Contract::create(['quality_issues' => [], 'tipo' => 'Tipo sem equivalência confirmada']);
        $deleted = Contract::create(['quality_issues' => [], 'tipo' => 'Servicos Diversos']);
        $deleted->delete();
        $correction = app(CorrectHistoricalContractTypes::class);
        $this->assertSame(1, $correction->execute());
        $this->assertSame('Servicos Diversos', $contract->fresh()->tipo);
        $this->assertDatabaseCount('contract_audits', 0);
        $this->assertSame(1, $correction->execute(false));
        $this->assertSame('Serviços Diversos', $contract->fresh()->tipo);
        $this->assertSame(5, $contract->fresh()->version);
        $this->assertSame('Preservar', $contract->fresh()->objeto);
        $this->assertSame('Tipo sem equivalência confirmada', $unknown->fresh()->tipo);
        $this->assertSame('Servicos Diversos', Contract::withTrashed()->findOrFail($deleted->id)->tipo);
        $audit = ContractAudit::sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame(['before' => 'Servicos Diversos', 'after' => 'Serviços Diversos'], $audit->changes['tipo']);
        $this->assertSame(0, $correction->execute(false));
    }

    public function test_audit_failure_rolls_back_type_and_version(): void
    {
        $contract = Contract::create(['quality_issues' => [], 'tipo' => 'Servicos Diversos', 'version' => 4]);
        ContractAudit::creating(fn () => throw new RuntimeException('Audit unavailable'));
        try {
            app(CorrectHistoricalContractTypes::class)->execute(false);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
            $this->assertSame('Servicos Diversos', $contract->fresh()->tipo);
            $this->assertSame(4, $contract->fresh()->version);
        } finally {
            ContractAudit::flushEventListeners();
        }
    }

    public function test_help_offers_only_confirmed_corrections_without_changing_saved_type(): void
    {
        $contract = Contract::create(['quality_issues' => [], 'tipo' => 'Servicos Diversos']);
        $html = Blade::render('<x-contract-type-help :contract="$contract"/>', compact('contract'));
        $this->assertStringContainsString('Aceitar correção', $html);
        $this->assertStringContainsString('Manter valor histórico', $html);
        $this->assertStringContainsString('data-contract-type-correction="Serviços Diversos"', $html);
        $this->assertSame('Servicos Diversos', $contract->fresh()->tipo);
        $contract->tipo = 'Tipo sem equivalência confirmada';
        $html = Blade::render('<x-contract-type-help :contract="$contract"/>', compact('contract'));
        $this->assertStringNotContainsString('Aceitar correção', $html);
        $this->assertStringContainsString('Não há correção automática confirmada', $html);
    }

    public function test_equipment_rental_aliases_are_corrected_and_offered_without_modifying_other_business_fields(): void
    {
        foreach (['Locação de Equipamentos', 'Locação de Bens Móveis'] as $type) {
            $contract = Contract::create(['quality_issues' => [], 'tipo' => $type, 'version' => 2,
                'objeto' => 'Locação de tendas e gerador', 'valor_total' => '1234.5600']);
            $html = Blade::render('<x-contract-type-help :contract="$contract"/>', compact('contract'));
            $this->assertStringContainsString('data-contract-type-correction="Locação de Equipamento"', $html);
            $this->assertStringContainsString('Manter valor histórico', $html);
            $before = $contract->fresh()->getAttributes();
            $this->assertSame(1, app(CorrectHistoricalContractTypes::class)->execute());
            $this->assertSame($type, $contract->fresh()->tipo);
            $this->assertSame(1, app(CorrectHistoricalContractTypes::class)->execute(false));
            $after = $contract->fresh()->getAttributes();
            $this->assertSame('Locação de Equipamento', $after['tipo']);
            $this->assertSame(3, $after['version']);
            foreach (['tipo', 'version', 'updated_at', 'updated_by'] as $field) {
                unset($before[$field], $after[$field]);
            }
            $this->assertSame($before, $after);
            $audit = ContractAudit::where('contract_id', $contract->id)->sole();
            $this->assertSame(['before' => $type, 'after' => 'Locação de Equipamento'], $audit->changes['tipo']);
        }
        $this->assertSame(0, app(CorrectHistoricalContractTypes::class)->execute(false));
    }
}
