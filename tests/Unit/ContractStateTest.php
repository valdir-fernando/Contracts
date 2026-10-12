<?php

namespace Tests\Unit;

use App\Domain\Contracts\ContractState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContractStateTest extends TestCase
{
    public function test_partial_patch_is_immutable_and_preserves_explicit_null_and_history(): void
    {
        $original = new ContractState(1, ['objeto' => 'Original', 'dados_veiculo' => 'Preservar', 'department_id' => 4, 'version' => 3]);
        $changed = $original->with(['objeto' => 'Alterado', 'department_id' => null]);
        $this->assertSame('Original', $original->get('objeto'));
        $this->assertSame('Alterado', $changed->get('objeto'));
        $this->assertSame('Preservar', $changed->get('dados_veiculo'));
        $this->assertNull($changed->get('department_id'));
        $this->assertSame($original->attributes(), $changed->original());
        $this->assertSame(3, $changed->version());
    }

    public function test_unchanged_inconsistent_legacy_dates_are_preserved_but_changed_dates_are_checked(): void
    {
        $historical = new ContractState(1, ['vigencia_inicio' => '2026-10-01 00:00:00', 'vigencia_fim_original' => '2026-09-01 00:00:00']);
        $this->assertNull($historical->with(['objeto' => 'Correção'])->invalidChangedEndDate());
        $this->assertSame('vigencia_fim_original', $historical->with(['vigencia_fim_original' => '2026-09-02'])->invalidChangedEndDate());
        $this->assertNull($historical->with(['vigencia_fim_original' => '2026-10-01'])->invalidChangedEndDate());
        $this->assertSame('vigencia_fim_original', (new ContractState(null, $historical->attributes()))->invalidChangedEndDate());
    }

    public static function exercises(): array
    {
        return [['019/26', '2025-01-01', 2026], ['019/1999', null, 1999], ['019', '2024-02-29', 2024], ['019/123', null, null], [null, null, null]];
    }

    #[DataProvider('exercises')]
    public function test_exercise_uses_the_existing_number_suffix_or_signature_date(?string $number, ?string $signed, ?int $expected): void
    {
        $this->assertSame($expected, (new ContractState(null, ['numero' => $number, 'data_contrato' => $signed]))->exercise());
    }

    public function test_legacy_amendments_request_review_without_applying_dates_or_money(): void
    {
        $contract = new ContractState(null, ['n_aditivo' => ' 0 ', 'proc_aditivo' => '', 'valor_total' => '100.0000', 'vigencia_fim_original' => '2026-12-31']);
        $this->assertFalse($contract->requiresReview());
        $this->assertTrue($contract->with(['proc_aditivo' => 'PROC'])->requiresReview());
        $this->assertSame('100.0000', $contract->get('valor_total'));
        $this->assertSame('2026-12-31', $contract->get('vigencia_fim_original'));
        $this->assertTrue((new ContractState(1, ['vigencia_em_revisao' => true]))->requiresReview());
    }
}
