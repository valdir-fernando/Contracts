<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\ContractFinancialRecord;
use App\Models\User;
use App\Services\FinancialRecordWriter;
use App\Support\FinancialFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regular;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestingPassword123', 'user_type' => 'Administrador']);
        $this->regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestingPassword123']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'finance-scope'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $this->regular->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => '001/26', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => [],
            'fornecedor_nome_snapshot' => 'Fornecedor', 'fornecedor_documento_snapshot' => '52998224725', 'fundo_nome_snapshot' => 'Fundo',
            'processo' => 'PROC001', 'valor_total' => '5000.0000', 'valor_acumulado' => '5100.0000', 'vl_saldo_disp' => '4000.0000',
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-12-31']);
        $this->actingAs($this->admin);
    }

    private function base(): string
    {
        return '/contracts/'.$this->contract->id.'/finance';
    }

    private function data(string $kind = 'ficha', array $extra = []): array
    {
        $fields = match ($kind) {
            'dotacao' => ['Ficha' => '0007', 'Descricao' => 'Dotação sintética', 'Dotacao' => '01.02.003'],
            'empenho' => ['Empenho' => '0009', 'Valor' => '1.908,6300', 'Objeto' => 'Objeto sintético'],
            default => ['Ficha' => '0007', 'Valor' => '1.908,6300'],
        };

        return array_replace(['kind' => $kind, 'number' => '0001', 'year' => '2026', 'event_date' => '2026-10-11', 'fields' => $fields,
            'contract_version' => $this->contract->fresh()->version, 'reason' => 'Registro sintético para conferência'], $extra);
    }

    private function create(string $kind = 'ficha', array $extra = []): ContractFinancialRecord
    {
        $this->post($this->base(), $this->data($kind, $extra))->assertRedirect();

        return ContractFinancialRecord::latest('id')->firstOrFail();
    }

    public function test_source_fields_are_complete_and_all_kinds_preserve_contract_amounts_dates_and_scope_snapshots(): void
    {
        $dictionary = file_get_contents(base_path('docs/02_DICIONARIO_DE_DADOS_SQL.md'));
        foreach (['dotacao' => ['tb_Dotacao', 'ID_Dotacao'], 'ficha' => ['tb_ValorFicha', 'ID_Ficha']] as $kind => [$table, $key]) {
            $section = explode('### ', explode('### `'.$table.'`', $dictionary)[1])[0];
            preg_match_all('/\| `([^`]+)` \|/', $section, $matches);
            $this->assertSame(array_merge([$key], array_keys(FinancialFields::MAP[$kind])), $matches[1]);
        }
        foreach (array_keys(FinancialFields::KINDS) as $kind) {
            $data = $this->data($kind);
            foreach (FinancialFields::MAP[$kind] as $field => $definition) {
                if (! str_starts_with($definition[1], 'readonly')) {
                    $data['fields'][$field] = $definition[1] === 'money' ? '1.908,6300' : '0007';
                }
            }
            $record = $this->create($kind, $data);
            $expectedKeys = array_keys(FinancialFields::MAP[$kind]);
            $actualKeys = array_keys($record->payload);
            sort($expectedKeys);
            sort($actualKeys);
            $this->assertSame($expectedKeys, $actualKeys);
            $this->assertSame('0001', $record->number);
            $this->assertSame('Fundo', $record->payload['Fundo']);
            $this->get($this->base().'/'.$record->id)->assertOk()->assertSee('0007');
            $this->get($this->base().'/'.$record->id.'/edit')->assertOk();
            foreach (FinancialFields::MAP[$kind] as $definition) {
                $this->get($this->base().'/'.$record->id)->assertSee($definition[0]);
            }
        }
        $this->assertSame('5000.0000', $this->contract->fresh()->valor_total);
        $this->assertSame('5100.0000', $this->contract->fresh()->valor_acumulado);
        $this->assertSame('4000.0000', $this->contract->fresh()->vl_saldo_disp);
        $this->assertSame('2026-12-31', $this->contract->fresh()->end_date->toDateString());
        $this->assertDatabaseCount('contract_audits', 3);
    }

    public function test_common_user_can_prepare_drafts_but_cannot_register_edit_registered_delete_or_read_outside_scope(): void
    {
        $this->actingAs($this->regular);
        $record = $this->create();
        $this->get($this->base().'/'.$record->id)->assertOk()->assertDontSee('52998224725')->assertSee('Documento pessoal protegido');
        $this->put($this->base().'/'.$record->id, $this->data('ficha', ['version' => $record->version, 'status' => 'registrado']))->assertForbidden();
        $record->update(['status' => 'registrado']);
        $this->get($this->base().'/'.$record->id.'/edit')->assertForbidden();
        $this->put($this->base().'/'.$record->id, $this->data('ficha', ['version' => $record->version]))->assertForbidden();
        $this->delete($this->base().'/'.$record->id, $this->data('ficha', ['version' => $record->version, 'confirmation' => $record->number]))->assertForbidden();
        $this->get($this->base().'?deleted=1')->assertForbidden();
        $this->regular->scopes()->detach();
        $this->get($this->base())->assertForbidden();
        $this->get($this->base().'/'.$record->id)->assertForbidden();
        $this->post($this->base(), $this->data('dotacao'))->assertForbidden();
    }

    public function test_server_rejects_unknown_fields_invalid_money_dates_exercise_and_cross_contract_links(): void
    {
        foreach (['fields' => [['Ficha' => '0007', 'Valor' => '-1'], ['Ficha' => '0007', 'Valor' => '0.00001'], ['Ficha' => '0007', 'Valor' => ['1']], ['Ficha' => '0007', 'Valor' => '1', 'CPF_CNPJ' => '123'], ['Ficha' => '0007', 'Valor' => '1', 'ID_Ficha' => '1']],
            'year' => [999, 10000], 'event_date' => ['0000-00-00', '2026-02-30'], 'number' => ['   ']] as $key => $values) {
            foreach ($values as $value) {
                $this->post($this->base(), $this->data('ficha', [$key => $value]))->assertSessionHasErrors();
                $this->assertDatabaseCount('contract_financial_records', 0);
            }
        }
        $budget = $this->create('dotacao');
        $other = $this->contract->replicate();
        $other->numero = '002/26';
        $other->save();
        $budget->update(['contract_id' => $other->id]);
        $this->post($this->base(), $this->data('ficha', ['budget_id' => $budget->id]))->assertSessionHasErrors('budget_id');
        $this->get($this->base().'/'.$budget->id)->assertNotFound();
        $this->put($this->base().'/'.$budget->id, $this->data('dotacao', ['version' => $budget->version]))->assertNotFound();
        $amendment = ContractAmendment::create(['contract_id' => $other->id, 'number' => '001', 'event_date' => '2026-10-11', 'payload' => [], 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]);
        $this->post($this->base(), $this->data('ficha', ['amendment_id' => $amendment->id]))->assertSessionHasErrors('amendment_id');
    }

    public function test_sparse_updates_conflicts_explicit_links_and_removed_reference_history(): void
    {
        $budget = $this->create('dotacao');
        $record = $this->create('empenho', ['budget_id' => $budget->id]);
        $versions = ['version' => $record->version, 'contract_version' => $this->contract->fresh()->version, 'reason' => 'Atualização somente do valor', 'fields' => ['Valor' => '2000.0001']];
        $this->put($this->base().'/'.$record->id, $versions)->assertRedirect();
        $this->assertSame('0009', $record->fresh()->payload['Empenho']);
        $this->assertSame('2000.0001', $record->fresh()->amount);
        $this->put($this->base().'/'.$record->id, $versions)->assertConflict()->assertSee('2000.0001')->assertSee('disabled', false);
        $budget->delete();
        $this->get($this->base().'/'.$record->id)->assertOk()->assertSee('referência histórica');
        $this->get($this->base().'/'.$record->id.'/edit')->assertOk()->assertSee('Referência histórica');
        $versions['version'] = $record->fresh()->version;
        $versions['contract_version'] = $this->contract->fresh()->version;
        $this->put($this->base().'/'.$record->id, $versions)->assertRedirect();
        $this->assertSame($budget->id, $record->fresh()->budget_id);
        $versions['contract_version']--;
        $versions['number'] = '0002';
        unset($versions['version']);
        $versions['fields']['Ficha'] = '0007';
        $this->post($this->base(), $this->data('ficha', $versions))->assertConflict();
    }

    public function test_registered_totals_are_exact_separate_filtered_and_exclude_drafts_and_deleted(): void
    {
        $record = $this->create('ficha', ['fields' => ['Ficha' => '0007', 'Valor' => '0.0001'], 'status' => 'registrado']);
        $this->create('ficha', ['number' => '0002', 'fields' => ['Ficha' => '0007', 'Valor' => '0.0002'], 'status' => 'registrado']);
        $this->create('ficha', ['number' => '0003']);
        $this->create('empenho', ['status' => 'registrado']);
        $this->get($this->base())->assertViewHas('totals', ['ficha' => '0.0003', 'empenho' => '1908.6300']);
        $this->get($this->base().'?kind=ficha')->assertViewHas('totals', ['ficha' => '0.0003', 'empenho' => '0']);
        $this->get($this->base().'?year=2025')->assertViewHas('totals', ['ficha' => '0', 'empenho' => '0']);
        $record->delete();
        $this->get($this->base())->assertViewHas('totals', ['ficha' => '0.0002', 'empenho' => '1908.6300']);
        $this->get($this->base().'?deleted=1')->assertViewHas('totals', ['ficha' => '0.0001', 'empenho' => '0']);
    }

    public function test_delete_restore_confirmation_duplicate_reservation_and_contract_restore_preserve_records(): void
    {
        $record = $this->create();
        $data = ['version' => $record->version, 'contract_version' => $this->contract->fresh()->version, 'confirmation' => 'wrong', 'reason' => 'Exclusão para teste'];
        $this->delete($this->base().'/'.$record->id, $data)->assertSessionHasErrors('confirmation');
        $data['confirmation'] = $record->number;
        $this->delete($this->base().'/'.$record->id, $data)->assertRedirect();
        $this->assertSoftDeleted($record);
        $this->get($this->base().'/'.$record->id)->assertNotFound();
        $this->post($this->base(), $this->data())->assertSessionHasErrors('number');
        $this->post($this->base().'/'.$record->id.'/restore', $data)->assertConflict();
        $data['version']++;
        $data['contract_version']++;
        $this->post($this->base().'/'.$record->id.'/restore', $data)->assertRedirect();
        $this->assertNotSoftDeleted($record);
        $this->contract->delete();
        $this->get($this->base().'/'.$record->id)->assertNotFound();
        $this->contract->restore();
        $this->get($this->base().'/'.$record->id)->assertOk();
        $this->assertDatabaseCount('contract_audits', 3);
    }

    public function test_audit_failure_rolls_back_each_mutation_and_revoked_session_cannot_write(): void
    {
        $record = $this->create();
        $version = $this->contract->fresh()->version;
        ContractAudit::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            $writer = app(FinancialRecordWriter::class);
            foreach (['create', 'update', 'delete'] as $operation) {
                try {
                    if ($operation === 'delete') {
                        $writer->change($this->admin, $this->contract, $record->id, ['version' => 1, 'contract_version' => $version, 'reason' => 'Test rollback deletion', 'confirmation' => $record->number], false);
                    } else {
                        $writer->save($this->admin, $this->contract, 'ficha', $this->data('ficha', ['number' => '0002', 'version' => $operation === 'update' ? 1 : null]), $operation === 'update' ? $record->id : null);
                    }
                    $this->fail('Expected audit failure');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('audit unavailable', $exception->getMessage());
                }
                $this->assertDatabaseCount('contract_financial_records', 1);
                $this->assertSame($version, $this->contract->fresh()->version);
                $this->assertSame('0001', $record->fresh()->number);
                $this->assertNotSoftDeleted($record);
            }
        } finally {
            ContractAudit::flushEventListeners();
        }
        User::whereKey($this->admin->id)->increment('session_version');
        $this->post($this->base(), $this->data('dotacao'))->assertForbidden();
    }
}
