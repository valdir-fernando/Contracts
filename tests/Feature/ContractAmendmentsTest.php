<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAttachment;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\AmendmentWriter;
use App\Support\AmendmentFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractAmendmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regular;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $this->regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestPassword12345']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'amendments-scope'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $this->regular->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => '001/26', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => [],
            'fornecedor_nome_snapshot' => 'Fornecedor', 'fornecedor_documento_snapshot' => '52998224725',
            'cpf_gestor' => '11144477735', 'fundo_nome_snapshot' => 'Fundo', 'fundo_documento_snapshot' => '11222333000181',
            'valor_total' => '5000.0000', 'valor_acumulado' => '5100.0000', 'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-12-31']);
        $this->actingAs($this->admin);
    }

    private function base(): string
    {
        return '/contracts/'.$this->contract->id.'/amendments';
    }

    private function data(array $fields = [], array $extra = []): array
    {
        return array_replace(['fields' => array_replace(['N_ADT' => '001', 'Data_ADT' => '2026-10-11', 'Proc_ADT' => 'PROC-ADT',
            'Tipo_ADT' => 'Prorrogação', 'Motivo_ADT' => 'Descrição documental do aditivo'], $fields),
            'contract_version' => $this->contract->fresh()->version, 'reason' => 'Cadastro de aditivo para conferência'], $extra);
    }

    private function create(array $fields = [], array $extra = []): ContractAmendment
    {
        $this->post($this->base(), $this->data($fields, $extra))->assertRedirect();

        return ContractAmendment::sole();
    }

    public function test_all_44_fields_are_mapped_persisted_and_rendered_without_applying_amounts_or_periods(): void
    {
        $dictionary = file_get_contents(base_path('docs/02_DICIONARIO_DE_DADOS_SQL.md'));
        $section = explode('### `tb_ADM`', explode('### `tb_Aditivos`', $dictionary)[1])[0];
        preg_match_all('/^\| `([^`]+)` \|/m', $section, $matches);
        $this->assertSame($matches[1], array_merge(['ID'], array_keys(AmendmentFields::MAP)));
        $fields = [];
        foreach (AmendmentFields::MAP as $field => $definition) {
            if (in_array($field, AmendmentFields::DERIVED)) {
                continue;
            }
            $fields[$field] = match ($definition['type']) {
                'date' => '2027-01-01', 'money' => '1.908,6300', default => 'Texto '.$field,
            };
        }
        $fields['N_ADT'] = '00001';
        $fields['CPF_Socio'] = '52998224725';
        $fields['CPF_Gestor'] = '11144477735';
        $document = ContractAttachment::create(['contract_id' => $this->contract->id, 'uploaded_by' => $this->admin->id, 'name' => 'Documento',
            'path' => 'doc.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64)]);
        $amendment = $this->create($fields, ['attachment_id' => $document->id, 'status' => 'registrado']);
        $this->assertCount(43, $amendment->payload);
        $this->assertSame('00001', $amendment->number);
        $this->assertSame('1908.6300', $amendment->amount);
        $this->assertSame('1908.6300', $amendment->payload['VL_Acumulado']);
        $this->assertSame('001/26', $amendment->payload['Contrato']);
        $this->assertSame($this->admin->id, $amendment->created_by);
        $response = $this->get($this->base().'/'.$amendment->id)->assertOk()->assertSee('Baixar documento do aditivo');
        foreach (AmendmentFields::MAP as $definition) {
            $response->assertSee($definition['label']);
        }
        $this->assertSame('5000.0000', $this->contract->fresh()->valor_total);
        $this->assertSame('5100.0000', $this->contract->fresh()->valor_acumulado);
        $this->assertSame('2026-12-31', $this->contract->fresh()->vigencia_fim_original->toDateString());
        $this->assertNull($this->contract->fresh()->vigencia_fim_atual);
        $this->assertTrue($this->contract->fresh()->vigencia_em_revisao);
        $this->assertDatabaseHas('contract_audits', ['action' => 'amendment_created', 'actor_id' => $this->admin->id]);
        $this->get('/reports?report=changes')->assertViewHas('contracts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $this->contract->id);
    }

    public function test_common_user_prepares_drafts_but_cannot_register_modify_registered_or_fabricate_scope(): void
    {
        $this->actingAs($this->regular)->get($this->base().'/create')->assertOk()->assertDontSee('11144477735')->assertDontSee('52998224725');
        $amendment = $this->create();
        $this->assertFalse($this->contract->fresh()->vigencia_em_revisao);
        $this->get($this->base().'/'.$amendment->id)->assertOk()->assertDontSee('11144477735')->assertDontSee('52998224725');
        $this->put($this->base().'/'.$amendment->id, $this->data([], ['version' => 1, 'status' => 'registrado']))->assertForbidden();
        $this->put($this->base().'/'.$amendment->id, $this->data(['CPF_Gestor' => '52998224725'], ['version' => 1]))->assertSessionHasErrors('fields.CPF_Gestor');
        $other = Contract::create(['numero' => 'SEGREDO', 'quality_issues' => []]);
        foreach (['', '/create', '/'.$amendment->id, '/'.$amendment->id.'/edit'] as $suffix) {
            $this->get('/contracts/'.$other->id.'/amendments'.$suffix)->assertForbidden();
        }
        $this->actingAs($this->admin)->put($this->base().'/'.$amendment->id, $this->data([], ['version' => 1, 'status' => 'registrado']))->assertRedirect();
        $this->actingAs($this->regular)->get($this->base().'/'.$amendment->id.'/edit')->assertForbidden();
        $this->put($this->base().'/'.$amendment->id, $this->data([], ['version' => 2, 'status' => 'rascunho']))->assertForbidden();
        $this->get($this->base().'?deleted=1')->assertForbidden();
        $this->regular->scopes()->detach();
        $this->get($this->base())->assertForbidden();
        $this->post($this->base(), $this->data())->assertForbidden();
    }

    public function test_server_rejects_invalid_fields_numbers_dates_money_and_document_from_another_contract(): void
    {
        $this->post($this->base(), $this->data(['ID' => '999', 'Contrato' => 'Outro']))->assertSessionHasErrors(['fields', 'fields.Contrato']);
        $this->post($this->base(), $this->data(['Data_ADT' => '0000-00-00', 'VL_ADT' => '1,99999', 'CPF_Gestor' => '11111111111']))->assertSessionHasErrors(['fields.Data_ADT', 'fields.VL_ADT', 'fields.CPF_Gestor']);
        $this->post($this->base(), $this->data(['Incio_Prorrog' => '2027-02-01', 'Final_Prorrog' => '2027-01-01']))->assertSessionHasErrors('fields.Final_Prorrog');
        $this->postJson($this->base(), $this->data(['VL_ADT' => ['invalid']]))->assertUnprocessable()->assertJsonValidationErrors('fields.VL_ADT');
        $other = Contract::create(['quality_issues' => []]);
        $document = ContractAttachment::create(['contract_id' => $other->id, 'uploaded_by' => $this->admin->id, 'name' => 'Outro',
            'path' => 'other.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64)]);
        $this->post($this->base(), $this->data([], ['attachment_id' => $document->id]))->assertSessionHasErrors('attachment_id');
        $this->assertDatabaseCount('contract_amendments', 0);
    }

    public function test_update_preserves_omitted_fields_and_conflicts_preserve_typed_values_without_writing(): void
    {
        $amendment = $this->create(['Lei' => 'Texto de referência', 'VL_ADT' => '1.908,6300']);
        $url = $this->base().'/'.$amendment->id;
        $this->put($url, ['contract_version' => 2, 'version' => 1, 'reason' => 'Ajuste documental', 'fields' => ['Motivo_ADT' => 'Motivo revisado']])->assertRedirect();
        $this->assertSame('Texto de referência', $amendment->fresh()->payload['Lei']);
        $this->assertSame('1908.6300', $amendment->fresh()->amount);
        $this->put($url, ['contract_version' => 2, 'version' => 1, 'reason' => 'Aba desatualizada', 'fields' => ['Motivo_ADT' => 'Texto digitado antigo']])->assertStatus(409)->assertSee('Texto digitado antigo')->assertSee('Recarregar dados');
        $this->assertSame('Motivo revisado', $amendment->fresh()->payload['Motivo_ADT']);
        $this->post($this->base(), $this->data(['N_ADT' => '002'], ['contract_version' => 1]))->assertStatus(409)->assertSee('002');
        $this->assertDatabaseCount('contract_amendments', 1);
        $this->assertDatabaseHas('contract_audits', ['action' => 'amendment_updated']);
    }

    public function test_soft_delete_restore_confirmations_and_duplicate_numbers_preserve_history(): void
    {
        $amendment = $this->create([], ['status' => 'registrado']);
        $url = $this->base().'/'.$amendment->id;
        $data = ['contract_version' => 2, 'version' => 1, 'confirmation' => '001', 'reason' => 'Remover registro para revisão'];
        $this->actingAs($this->regular)->delete($url, $data)->assertForbidden();
        $this->actingAs($this->admin)->delete($url, array_replace($data, ['confirmation' => 'errado']))->assertSessionHasErrors('confirmation');
        $this->delete($url, $data)->assertRedirect();
        $this->assertSoftDeleted($amendment);
        $this->get($url)->assertNotFound();
        $this->post($this->base(), $this->data())->assertSessionHasErrors('fields.N_ADT');
        $this->post($url.'/restore', ['contract_version' => 3, 'version' => 2, 'confirmation' => '001', 'reason' => 'Restaurar registro revisado'])->assertRedirect();
        $this->get($url)->assertOk();
        $this->assertNotSoftDeleted($amendment);
        $this->assertTrue($this->contract->fresh()->vigencia_em_revisao);
        $this->contract->delete();
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('contract_amendments', ['id' => $amendment->id, 'deleted_at' => null]);
        $this->contract->restore();
        $this->get($url)->assertOk();
        $this->assertDatabaseHas('contract_audits', ['action' => 'amendment_deleted']);
        $this->assertDatabaseHas('contract_audits', ['action' => 'amendment_restored']);
    }

    public function test_audit_failure_rolls_back_amendment_parent_version_and_review_flag(): void
    {
        ContractAudit::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            app(AmendmentWriter::class)->save($this->admin, $this->contract, $this->data([], ['status' => 'registrado']));
            $this->fail('Expected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertDatabaseCount('contract_amendments', 0);
        $this->assertSame(1, $this->contract->fresh()->version);
        $this->assertFalse($this->contract->fresh()->vigencia_em_revisao);
    }

    public function test_sibling_contract_id_and_revoked_actor_session_cannot_mutate_amendment(): void
    {
        $amendment = $this->create();
        $other = Contract::create(['quality_issues' => []]);
        $this->get('/contracts/'.$other->id.'/amendments/'.$amendment->id)->assertNotFound();
        $this->put('/contracts/'.$other->id.'/amendments/'.$amendment->id, $this->data([], ['version' => 1]))->assertNotFound();
        DB::table('users')->where('id', $this->admin->id)->update(['session_version' => 1]);
        $this->post($this->base(), $this->data(['N_ADT' => '002']))->assertForbidden();
        $this->assertDatabaseCount('contract_amendments', 1);
    }
}
