<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Models\User;
use App\Services\ContractWriter;
use App\Support\ContractFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ContractWritingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fund;

    private int $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin fictício', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['legacy_id' => 1, 'name' => 'MUNICIPIO DE NIQUELÂNDIA', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'document' => '11222333000181', 'raw_payload' => '{}']);
        $this->department = DB::table('contract_departments')->insertGetId(['legacy_id' => 1, 'name' => 'Secretaria A', 'name_key' => 'SECRETARIA A', 'raw_payload' => '{}']);
        foreach (['11222333000181', '52998224725'] as $document) {
            DB::table('suppliers')->insert(['name' => 'Fornecedor fictício', 'name_key' => 'FORNECEDOR FICTICIO',
                'document' => $document, 'document_key' => $document,
                'raw_payload' => json_encode(['Endereco' => 'Rua do fornecedor', 'RG' => 'RG-PRIVADO', 'CPF_Socio' => '52998224725'], JSON_THROW_ON_ERROR)]);
        }
        $this->seedContractInspector($this->fund, $this->department);
        $this->actingAs($this->admin);
        $this->withSession(['fund_id' => $this->fund]);
    }

    public function test_save_confirmation_renders_with_maintenance_audit_metadata(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $changes = ['version' => ['before' => 1, 'after' => 2],
            'private_backup' => ['path' => 'private/sensitive-backup.json', 'sha256' => str_repeat('a', 64)],
            'ficha_record_ids' => ['before' => [1, 2], 'after' => [1]],
            'legacy_note' => 'Technical metadata', 'missing_before' => ['after' => 'Hidden technical value']];
        $audit = ContractAudit::create(['contract_id' => $contract->id, 'actor_id' => $this->admin->id,
            'action' => 'ficha_values_cleaned', 'changes' => $changes, 'created_at' => now()]);
        $response = $this->put('/contracts/'.$contract->id, ['version' => $contract->version,
            'reason' => 'Atualização de teste', 'objeto' => 'Objeto atualizado']);
        $response->assertRedirect(route('contracts.show', $contract))->assertSessionHas('status', 'Contrato atualizado.');
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Contrato atualizado.')
            ->assertSee('Histórico de alterações')->assertSee('Objeto atualizado')
            ->assertDontSee('private/sensitive-backup.json')->assertDontSee('Technical metadata')
            ->assertDontSee('Hidden technical value')->assertDontSee(str_repeat('a', 64));
        $this->assertSame($changes, $audit->fresh()->changes);
    }

    public function test_historical_type_can_be_kept_or_corrected_with_audited_save(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $contract->update(['tipo' => 'Servicos Diversos']);
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertSee('Servicos Diversos (histórico)')->assertSee('Aceitar correção')->assertSee('Manter valor histórico');
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'reason' => 'Manter valor histórico', 'tipo' => 'Servicos Diversos'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Servicos Diversos', $contract->fresh()->tipo);
        $this->put('/contracts/'.$contract->id, ['version' => $contract->fresh()->version, 'reason' => 'Corrigir grafia histórica', 'tipo' => 'Serviços Diversos'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Serviços Diversos', $contract->fresh()->tipo);
        $audit = ContractAudit::where('contract_id', $contract->id)->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(['before' => 'Servicos Diversos', 'after' => 'Serviços Diversos'], $audit->changes['tipo']);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['numero' => '001/26', 'processo' => 'PROC-001', 'fornecedor_nome_snapshot' => 'Fornecedor fictício',
            'fornecedor_documento_snapshot' => '11.222.333/0001-81', 'objeto' => 'Objeto fictício', 'tipo' => 'Serviço',
            'data_contrato' => '2026-10-01', 'vigencia_inicio' => '2026-10-01', 'vigencia_fim_original' => '2027-10-01',
            'valor_total' => '1.908,6300', 'modalidade' => 'Pregão', 'n_licit' => '001/2026', 'fund_id' => $this->fund, 'department_id' => $this->department], $extra);
    }

    public function test_guarantee_percentage_requires_between_one_hundredth_and_one_hundred_percent(): void
    {
        foreach (['0', '-1', '100,01%', 'abc', '0,001%'] as $value) {
            $this->post('/contracts', $this->data(['garantia' => $value]))->assertSessionHasErrors('garantia');
        }
        $this->assertDatabaseCount('contracts', 0);
        $this->post('/contracts', $this->data(['garantia' => '0,01%']))->assertRedirect()->assertSessionHasNoErrors();
        $contract = Contract::firstOrFail();
        $this->assertSame('0,01%', $contract->garantia);
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'reason' => 'Atualizar garantia', 'garantia' => '100,01%'])
            ->assertSessionHasErrors('garantia');
        $this->assertSame('0,01%', $contract->fresh()->garantia);
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'reason' => 'Atualizar garantia', 'garantia' => '100,00%'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('100,00%', $contract->fresh()->garantia);
    }

    public function test_service_order_summary_is_only_shown_for_saved_service_and_construction_contracts(): void
    {
        $this->get('/contracts/create')->assertOk()->assertDontSee('id="n_os"', false)->assertDontSee('id="data_os"', false)
            ->assertSee('id="prazo_obra" name="prazo_obra" disabled', false);
        $this->post('/contracts', $this->data(['prazo_obra' => 'Alteração manual']))->assertSessionHasErrors('prazo_obra');
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $contract->update(['n_os' => 'OS-123', 'data_os' => '2026-10-20']);
        foreach (['Serviço', 'Serviços Diversos', 'Obras'] as $type) {
            $contract->update(['tipo' => $type]);
            $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
                ->assertSeeInOrder(['id="prazo_obra"', 'contract-service-order-summary', 'Ordem Serviço', 'OS-123', 'Data OS', '20/10/2026'], false)
                ->assertDontSee('name="n_os"', false)->assertDontSee('name="data_os"', false);
        }
        $contract->update(['tipo' => 'Fornecimento']);
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertDontSee('id="n_os"', false)->assertDontSee('id="data_os"', false);
    }

    public function test_lookup_opens_saved_fields_and_historical_people_despite_unrelated_form_input(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $contract->update(['fundo_nome_snapshot' => 'MUNICIPIO HISTÓRICO', 'end_fundo' => 'ENDEREÇO CONTRATANTE HISTÓRICO',
            'nome_gestor' => 'GESTOR HISTÓRICO', 'nome_fiscal' => 'FISCAL HISTÓRICO', 'decreto_fiscal' => 'DECRETO HISTÓRICO',
            'fornecedor_nome_snapshot' => 'CONTRATADO HISTÓRICO', 'end_fornec' => 'ENDEREÇO CONTRATADO HISTÓRICO',
            'socio' => 'SÓCIO HISTÓRICO', 'end_imovel' => 'IMÓVEL SALVO', 'instalacoes' => 'DESTINAÇÃO SALVA',
            'tipo' => 'Locação de Imóvel', 'objeto' => 'OBJETO SALVO', 'cpf_fiscal' => '11144477735']);
        $lookup = $this->getJson(route('contracts.lookup', ['q' => $contract->numero, 'exact' => 1]))->assertOk();
        $url = $lookup->json('contracts.0.url');
        foreach (['', (string) ($contract->id + 1), ['invalid']] as $otherForm) {
            $response = $this->withSession(['_old_input' => ['_contract_form_id' => $otherForm, 'objeto' => 'OBJETO DO OUTRO FORMULÁRIO',
                'processo' => 'PROCESSO DO OUTRO FORMULÁRIO', 'tipo' => 'Show', 'department_id' => 999,
                'data_contrato' => '2020-01-01', 'end_imovel' => 'IMÓVEL DO OUTRO FORMULÁRIO']])->get($url)->assertOk();
            foreach (['MUNICIPIO HISTÓRICO', 'ENDEREÇO CONTRATANTE HISTÓRICO', 'GESTOR HISTÓRICO', 'FISCAL HISTÓRICO', 'DECRETO HISTÓRICO',
                'CONTRATADO HISTÓRICO', 'ENDEREÇO CONTRATADO HISTÓRICO', 'SÓCIO HISTÓRICO', 'IMÓVEL SALVO', 'DESTINAÇÃO SALVA', 'OBJETO SALVO'] as $text) {
                $response->assertSee($text);
            }
            $response->assertDontSee('OBJETO DO OUTRO FORMULÁRIO')->assertDontSee('IMÓVEL DO OUTRO FORMULÁRIO')
                ->assertSee('value="2026-10-01"', false)->assertSee('value="Locação de Imóvel" selected', false)
                ->assertSee('data-preserve-fiscal="true"', false);
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame('MUNICIPIO HISTÓRICO', trim($xpath->evaluate('string(//strong[contains(@class,"selected-fund-name")])')));
        }
        $this->assertSame('FISCAL HISTÓRICO', $contract->fresh()->nome_fiscal);
    }

    public function test_old_values_are_restored_only_for_the_same_contract_form_after_validation_failure(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->withSession(['_old_input' => ['_contract_form_id' => (string) $contract->id, 'objeto' => 'Correção ainda não salva', 'data_contrato' => '2026-09-20']])
            ->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertSee('Correção ainda não salva')->assertSee('value="2026-09-20"', false);
    }

    public function test_contract_type_select_uses_fixed_options_ordered_by_character_count_and_preserves_history(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data(['tipo' => 'Tipo importado']));
        $this->actingAs($this->regular());
        $response = $this->get('/contracts/create')->assertOk()
            ->assertSee('<select id="tipo" name="tipo"', false)
            ->assertViewHas('contractTypes', fn ($types) => $types->all() === [
                'Locação de Equipamento', 'Transporte Diversos', 'Locação de Veículo', 'Transporte Pessoas',
                'Transporte Escolar', 'Serviços Diversos', 'Locação de Imóvel', 'Credenciamento',
                'Fornecimento', 'Obras', 'Show',
            ])->assertDontSee('value="Tipo importado"', false);
        foreach (ContractFields::CONTRACT_TYPES as $type) {
            $this->assertSame(1, substr_count($response->getContent(), 'value="'.$type.'"'));
        }
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertSee('value="Tipo importado" selected', false);
    }

    public function test_editor_initializes_first_panel_and_focuses_the_enabled_administration_field(): void
    {
        $response = $this->get('/contracts/create')->assertOk()
            ->assertSee('x-data="{ step: 0, all: false }"', false)
            ->assertSee("x-init=\"\$nextTick(() => (\$el.querySelector('#department_id:not(:disabled)') || \$el.querySelector('#tipo'))?.focus({ preventScroll: true }))\"", false)
            ->assertDontSee('name="department_id" disabled', false);
        $this->assertStringContainsString(route('contracts.create'), $response->getContent());

        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo de Saúde', 'name_key' => 'FUNDO DE SAUDE', 'raw_payload' => '{}']);
        $this->withSession(['fund_id' => $otherFund])->get('/contracts/create')->assertOk()
            ->assertSee('x-data="{ step: 0, all: false }"', false)
            ->assertSee('name="department_id" disabled', false)
            ->assertSee('<select id="tipo" name="tipo"', false);

        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertSee('x-data="{ step: 0, all: false }"', false)
            ->assertSee('name="department_id" disabled', false);
    }

    public function test_editor_action_toolbar_respects_persistence_and_delete_permission(): void
    {
        $response = $this->get('/contracts/create')->assertOk()
            ->assertSee('contract-action-toolbar', false)
            ->assertSee('Salvar</button>', false)
            ->assertSee('Novo</a>', false)->assertDontSee('Editar Contrato')
            ->assertDontSee('>Cancelar</a>', false);
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $total = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " contract-action-toolbar ")]//input[@id="contract-items-total" and @readonly and not(@name)]');
        $this->assertSame(1, $total->length);
        $this->assertSame('', $total->item(0)->getAttribute('value'));
        $this->assertSame('A calcular', $total->item(0)->getAttribute('placeholder'));
        foreach (['Excluir'] as $label) {
            $buttons = $xpath->query('//button[@type="button" and @disabled and normalize-space(.)="'.$label.'"]');
            $this->assertSame(1, $buttons->length);
            $this->assertSame(1, $buttons->item(0)->getElementsByTagName('svg')->length);
        }
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertSee('href="'.route('contracts.show', $contract).'#delete-contract"', false)
            ->assertDontSee('Editar Contrato')->assertSee('Salvar</button>', false);
        $this->get('/contracts/'.$contract->id)->assertOk()->assertSee('id="delete-contract"', false);
        $this->actingAs($this->regular())->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertDontSee('Excluir</a>', false)->assertSee('Novo</a>', false)->assertDontSee('Editar Contrato');
    }

    public function test_guarantee_fields_are_in_validity_panel_instead_of_financial_panel(): void
    {
        $response = $this->get('/contracts/create')->assertOk();
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        foreach (['tipo_garantia', 'garantia', 'vl_garantia', 'prazo_obra'] as $field) {
            $this->assertSame(1, $xpath->query('//section[@data-editor-section="4"]//*[@name="'.$field.'"]')->length);
            $this->assertSame(0, $xpath->query('//section[@data-editor-section="5"]//*[@name="'.$field.'"]')->length);
        }
        $row = '//div[contains(concat(" ", normalize-space(@class), " "), " contract-guarantee-row ")]';
        foreach (['tipo_garantia', 'garantia', 'vl_garantia', 'prazo_obra'] as $field) {
            $this->assertSame(1, $xpath->query($row.'//*[@name="'.$field.'"]')->length);
        }
        $this->assertSame(0, $xpath->query($row.'//*[@name="ext_garantia"]')->length);
        $response->assertSee('Final da Vigência')->assertSee('(%) Garantia')->assertDontSee('name="ext_garantia"', false);
    }

    public function test_editor_omits_reason_panel_and_submits_default_audit_reason(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $response = $this->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertDontSee('Justificativa da alteração');
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $reason = (new \DOMXPath($html))->query('//input[@type="hidden" and @name="reason"]');
        $this->assertSame(1, $reason->length);
        $value = $reason->item(0)->getAttribute('value');
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'reason' => $value, 'objeto' => 'Objeto atualizado'])->assertSessionHasNoErrors()->assertRedirect();
        $audit = ContractAudit::where('contract_id', $contract->id)->latest('id')->firstOrFail();
        $this->assertSame($value, $audit->reason);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame('Objeto atualizado', $audit->changes['objeto']['after']);
    }

    public function test_removed_financial_controls_preserve_stored_history(): void
    {
        $history = ['vl_saldo_disp' => '123.4500', 'ext_parcial' => 'Valor parcial histórico', 'ext_total' => 'Valor total histórico',
            'ext_acumulado' => 'Valor acumulado histórico', 'ficha' => 'FICHA-01', 'empenho' => 'EMPENHO-01'];
        $contract = app(ContractWriter::class)->save($this->admin, $this->data($history));
        foreach (['/contracts/create', '/contracts/'.$contract->id.'/edit'] as $url) {
            $response = $this->get($url)->assertOk();
            foreach (array_keys($history) as $column) {
                $response->assertDontSee('name="'.$column.'"', false);
            }
            $response->assertSee('id="valor_total"', false)->assertDontSee('name="vl_saldo_contrato"', false);
        }
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'reason' => 'Revisão cadastral', 'objeto' => 'Objeto atualizado'])->assertRedirect();
        foreach ($history as $column => $value) {
            $this->assertSame($value, $contract->fresh()->$column);
        }
    }

    public function test_financial_panel_displays_readonly_totals_and_allows_creation_before_item_totals(): void
    {
        $data = $this->data();
        unset($data['valor_total']);
        $this->post('/contracts', $data)->assertSessionHasNoErrors()->assertRedirect();
        $contract = Contract::firstOrFail();
        $this->assertNull($contract->valor_total);
        $contract->forceFill(['vl_parcial' => '125.0000', 'valor_total' => '1500.0000', 'valor_acumulado' => '250.0000', 'vl_saldo_contrato' => '1250.0000',
            'ext_parcial' => 'Cento e vinte e cinco reais', 'ext_total' => 'Mil e quinhentos reais', 'ext_acumulado' => 'Duzentos e cinquenta reais'])->save();
        $response = $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertSee('Valor_Mensal')->assertSee('Valor do Contrato')->assertSee('Valor acumulado Aditivo')
            ->assertDontSee('name="vl_saldo_contrato"', false);
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        foreach (['vl_parcial' => 'R$ 125,00', 'valor_total' => 'R$ 1.500,00', 'valor_acumulado' => 'R$ 250,00'] as $column => $display) {
            $field = $xpath->query('//*[@id="'.$column.'" and @aria-readonly="true" and not(@name)]');
            $this->assertSame(1, $field->length);
            $this->assertSame($display, trim($field->item(0)->textContent));
            $response->assertDontSee('name="'.$column.'"', false);
        }
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'reason' => 'Revisão cadastral', 'objeto' => 'Objeto atualizado'])->assertRedirect();
        foreach (['vl_parcial' => '125.0000', 'valor_total' => '1500.0000', 'valor_acumulado' => '250.0000', 'vl_saldo_contrato' => '1250.0000'] as $column => $value) {
            $this->assertSame($value, $contract->fresh()->$column);
        }
        foreach (['vl_parcial' => 'Cento e vinte e cinco reais', 'valor_total' => 'Mil e quinhentos reais', 'valor_acumulado' => 'Duzentos e cinquenta reais'] as $column => $words) {
            $message = $xpath->query('//*[@id="'.$column.'"]/following-sibling::p[@data-financial-words="'.$column.'"]');
            $this->assertSame(1, $message->length);
            $this->assertSame($words, trim($message->item(0)->textContent));
        }
    }

    public function test_guarantee_words_are_readonly_below_amount_and_preserved_when_omitted(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data(['vl_garantia' => '1000', 'ext_garantia' => 'Mil reais']));
        $response = $this->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertDontSee('name="ext_garantia"', false);
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $message = $xpath->query('//input[@name="vl_garantia"]/following-sibling::p[@class="contract-guarantee-message"]');
        $this->assertSame(1, $message->length);
        $this->assertSame('Mil reais', trim($message->item(0)->textContent));
        $this->assertTrue($message->item(0)->hasAttribute('hidden'));
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'reason' => 'Ajuste cadastral', 'objeto' => 'Objeto atualizado'])->assertRedirect();
        $this->assertSame('Mil reais', $contract->fresh()->ext_garantia);
        $this->assertSame('1000.0000', $contract->fresh()->vl_garantia);
    }

    public function test_execution_summary_is_readonly_and_rejects_changes_from_contract_form(): void
    {
        $history = ['vigencia_fim_atual' => '2028-10-01', 'n_aditivo' => 'ADITIVO-RESUMO',
            'proc_aditivo' => 'PROCESSO-RESUMO', 'n_os' => 'ORDEM-RESUMO', 'data_os' => '2026-10-05'];
        $contract = app(ContractWriter::class)->save($this->admin, $this->data($history));
        $create = $this->get('/contracts/create')->assertOk()->assertDontSee('contract-execution-summary', false);
        foreach (array_keys($history) as $column) {
            $create->assertDontSee('name="'.$column.'"', false);
        }
        $this->post('/contracts', $this->data($history))->assertSessionHasErrors(array_keys($history));
        $this->assertDatabaseCount('contracts', 1);
        $edit = $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertDontSee('aria-label="Resumo de execução e aditivos"', false)
            ->assertDontSee('contract-execution-summary', false)
            ->assertSee('ADITIVO-RESUMO')->assertSee('PROCESSO-RESUMO')
            ->assertSee('01/10/2028');
        $html = new \DOMDocument;
        @$html->loadHTML($edit->getContent());
        $xpath = new \DOMXPath($html);
        $row = '//div[contains(concat(" ", normalize-space(@class), " "), " contract-validity-end-row ")]';
        $this->assertSame(1, $xpath->query($row.'//*[@name="vigencia_fim_original"]')->length);
        $updated = $xpath->query($row.'//*[@id="vigencia_fim_atual" and @aria-readonly="true" and not(@name)]');
        $this->assertSame(1, $updated->length);
        $this->assertSame('01/10/2028', trim($updated->item(0)->textContent));
        foreach (['n_aditivo' => 'ADITIVO-RESUMO', 'proc_aditivo' => 'PROCESSO-RESUMO'] as $column => $value) {
            $field = $xpath->query($row.'//*[@id="'.$column.'" and @aria-readonly="true" and not(@name)]');
            $this->assertSame(1, $field->length);
            $this->assertSame($value, trim($field->item(0)->textContent));
        }
        foreach (array_keys($history) as $column) {
            $edit->assertDontSee('name="'.$column.'"', false);
        }
        $changes = ['vigencia_fim_atual' => '2029-10-01', 'n_aditivo' => 'ALTERADO',
            'proc_aditivo' => 'ALTERADO', 'n_os' => 'ALTERADO', 'data_os' => '2026-10-06'];
        $this->put('/contracts/'.$contract->id, $changes + ['version' => $contract->version, 'reason' => 'Tentativa de alteração'])
            ->assertSessionHasErrors(array_keys($history));
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'objeto' => 'Objeto revisado', 'reason' => 'Revisão de descrição'])->assertRedirect();
        foreach ($history as $column => $value) {
            $actual = in_array($column, ['vigencia_fim_atual', 'data_os']) ? $contract->fresh()->$column->format('Y-m-d') : $contract->fresh()->$column;
            $this->assertSame($value, $actual);
        }
    }

    public function test_header_lookup_limits_results_to_selected_fund_and_current_actor_scope(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        $other = $contract->replicate(['number_year', 'number_sequence']);
        $other->fund_id = $otherFund;
        $other->numero = 'OUTRO-999';
        $other->save();
        $hidden = $contract->replicate(['number_year', 'number_sequence']);
        $hidden->ownership_resolved = false;
        $hidden->numero = 'RESTRITO-999';
        $hidden->save();
        $deleted = $contract->replicate(['number_year', 'number_sequence']);
        $deleted->save();
        $deleted->delete();

        $this->actingAs($this->regular());
        $response = $this->getJson(route('contracts.lookup'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'contracts')
            ->assertJsonPath('contracts.0.number', $contract->numero)
            ->assertJsonPath('contracts.0.url', route('contracts.edit', $contract));
        $this->assertSame(['number', 'process', 'url'], array_keys($response->json('contracts.0')));
        $this->getJson(route('contracts.lookup', ['q' => 'OUTRO']))->assertOk()->assertJsonCount(0, 'contracts');
        $this->getJson(route('contracts.lookup', ['q' => '%']))->assertOk()->assertJsonCount(0, 'contracts');
        $this->getJson(route('contracts.lookup', ['q' => $contract->numero, 'fund' => $otherFund]))->assertOk()->assertJsonCount(1, 'contracts');
        $this->getJson(route('contracts.lookup', ['q' => $contract->numero, 'exact' => 1, 'fund' => $otherFund]))
            ->assertOk()->assertJsonCount(1, 'contracts')->assertJsonPath('contracts.0.url', route('contracts.edit', $contract));
        $this->getJson(route('contracts.lookup', ['q' => substr($contract->numero, 0, 2), 'exact' => 1]))->assertOk()->assertJsonCount(0, 'contracts');
        $this->getJson(route('contracts.lookup', ['q' => 'OUTRO-999', 'exact' => 1]))->assertOk()->assertJsonCount(0, 'contracts');
        $duplicate = $contract->replicate(['number_year', 'number_sequence']);
        $duplicate->save();
        $this->getJson(route('contracts.lookup', ['q' => $contract->numero, 'exact' => 1]))->assertOk()->assertJsonCount(2, 'contracts');
        $this->getJson(route('contracts.lookup', ['page' => 0]))->assertUnprocessable();
        $this->withSession(['fund_id' => $otherFund])->getJson(route('contracts.lookup'))->assertUnauthorized();
    }

    public function test_supplier_panel_omits_name_field_and_keeps_header_catalog_value(): void
    {
        $this->get('/contracts/create')->assertOk()
            ->assertDontSee('for="fornecedor_nome_snapshot"', false)
            ->assertSee('type="hidden" name="fornecedor_nome_snapshot" id="fornecedor_nome_snapshot" readonly', false)
            ->assertSee('data-contract-summary-field="fornecedor_nome_snapshot"', false);
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertDontSee('for="fornecedor_nome_snapshot"', false)
            ->assertSee('Fornecedor fictício');
    }

    public function test_notice_fields_are_editable_next_to_service_fields_in_special_data_panel(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data(['n_edital' => 'EDITAL-ANTIGO', 'proc_edital' => 'PROCESSO-ANTIGO']));
        foreach (['/contracts/create', '/contracts/'.$contract->id.'/edit'] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertSeeInOrder(['for="cargo_credenc"', 'for="local_servico"', 'for="n_edital"', 'for="proc_edital"'], false);
            $html = new \DOMDocument;
            @$html->loadHTML($response->getContent());
            $xpath = new \DOMXPath($html);
            $this->assertSame(2, $xpath->query('//section[@data-editor-section="3"]//input[@name="n_edital" or @name="proc_edital"]')->length);
        }
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'n_edital' => 'EDITAL-NOVO', 'proc_edital' => 'PROCESSO-NOVO', 'reason' => 'Correção dos dados do edital'])->assertRedirect();
        $this->assertSame('EDITAL-NOVO', $contract->fresh()->n_edital);
        $this->assertSame('PROCESSO-NOVO', $contract->fresh()->proc_edital);
    }

    public function test_scope_select_accepts_defined_options_and_preserves_existing_history(): void
    {
        $this->get('/contracts/create')->assertOk()->assertSee('<select id="escopo" name="escopo"', false)
            ->assertSee('value="Escopo"', false)->assertSee('value="Contínuo"', false)
            ->assertSee('aria-describedby="scope-help"', false)->assertSee('id="scope-help" class="field-help-popover" popover', false)
            ->assertSee('Tabela Comparativa')->assertSee('Contrato por Escopo')->assertSee('Contrato Continuado')
            ->assertSee('Objetivo Principal')->assertSee('Vigência e Prazos')->assertSee('necessidade permanente ou prolongada');
        $this->post('/contracts', $this->data(['escopo' => 'Inválido']))->assertSessionHasErrors('escopo');
        foreach (['Escopo', 'Contínuo'] as $scope) {
            $this->post('/contracts', $this->data(['escopo' => $scope, 'duplicate_confirmation' => true]))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($scope, Contract::latest('id')->firstOrFail()->escopo);
        }
        $contract = Contract::firstOrFail();
        $contract->update(['escopo' => 'Descrição histórica']);
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertSee('value="Descrição histórica" selected', false);
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'reason' => 'Preservar escopo histórico', 'objeto' => 'Objeto atualizado'])->assertRedirect();
        $this->assertSame('Descrição histórica', $contract->fresh()->escopo);
        $this->put('/contracts/'.$contract->id, ['version' => $contract->fresh()->version, 'reason' => 'Corrigir escopo informado', 'escopo' => 'Inválido'])->assertSessionHasErrors('escopo');
        $this->put('/contracts/'.$contract->id, ['version' => $contract->fresh()->version, 'reason' => 'Selecionar escopo contínuo', 'escopo' => 'Contínuo'])->assertRedirect();
        $this->assertSame('Contínuo', $contract->fresh()->escopo);
    }

    public function test_identification_form_hides_unused_controls_and_preserves_their_historical_values(): void
    {
        $response = $this->get('/contracts/create')->assertOk();
        foreach (['Código de origem', 'Identificação completa'] as $label) {
            $response->assertDontSee($label);
        }
        $historical = ['proc_edital' => 'PROC-LEGADO', 'n_edital' => 'EDITAL-LEGADO', 'data_licit' => '2026-09-01', 'data_cont_verd' => '2026-09-02', 'n_solicit' => 'SOL-LEGADA', 'tipo_fornecimento' => 'Fornecimento legado', 'tipo_obra' => 'Obra legada', 'reforma' => 'Reforma histórica'];
        $response = $this->get('/contracts/create')->assertOk();
        foreach (array_diff(array_keys($historical), ['proc_edital', 'n_edital']) as $column) {
            $response->assertDontSee('name="'.$column.'"', false);
        }
        $response->assertSee('id="modalidade"', false)->assertSee('id="n_licit"', false);
        $contract = app(ContractWriter::class)->save($this->admin, $this->data($historical));
        $response = $this->get('/contracts/'.$contract->id.'/edit')->assertOk();
        foreach (['legacy_id', 'numero', 'cont_inteiro'] as $column) {
            $response->assertDontSee('id="'.$column.'"', false)->assertDontSee('name="'.$column.'"', false);
        }
        $number = $contract->numero;
        $identification = $contract->cont_inteiro;
        foreach (array_diff(array_keys($historical), ['proc_edital', 'n_edital']) as $column) {
            $response->assertDontSee('name="'.$column.'"', false);
        }
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Correção de descrição', 'objeto' => 'Objeto corrigido'])->assertRedirect();
        $this->assertSame($number, $contract->fresh()->numero);
        $this->assertSame($identification, $contract->fresh()->cont_inteiro);
        foreach ($historical as $column => $value) {
            $actual = in_array($column, ['data_licit', 'data_cont_verd']) ? $contract->fresh()->$column->format('Y-m-d') : $contract->fresh()->$column;
            $this->assertSame($value, $actual);
        }
    }

    public function test_modality_and_tender_number_are_required_without_retroactively_blocking_incomplete_history(): void
    {
        foreach ([null, '', '   '] as $empty) {
            $this->post('/contracts', $this->data(['modalidade' => $empty, 'n_licit' => $empty]))
                ->assertSessionHasErrors(['modalidade', 'n_licit']);
        }
        $input = $this->data();
        unset($input['modalidade'], $input['n_licit']);
        $this->post('/contracts', $input)->assertSessionHasErrors(['modalidade', 'n_licit']);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_audits', 0);
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Tentativa de limpar licitação', 'modalidade' => null, 'n_licit' => null])
            ->assertSessionHasErrors(['modalidade', 'n_licit']);
        $contract->update(['modalidade' => null, 'n_licit' => null]);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Correção de registro histórico', 'objeto' => 'Objeto histórico corrigido'])
            ->assertRedirect();
        $this->assertNull($contract->fresh()->modalidade);
        $this->assertNull($contract->fresh()->n_licit);
    }

    private function regular(bool $scope = true): User
    {
        $user = User::create(['name' => 'Usuário fictício', 'username' => 'REGULAR', 'password' => 'SenhaFicticia123']);
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        if ($scope) {
            $scope = AccessScope::create(['scope_key' => hash('sha256', 'scope'), 'fund' => 'Fundo A', 'department' => 'Secretaria A',
                'fund_id' => $this->fund, 'department_id' => $this->department]);
            $user->scopes()->attach($scope);
        }

        return $user;
    }

    public function test_iptu_has_two_choices_and_rejects_other_new_values(): void
    {
        $response = $this->get('/contracts/create')->assertOk()->assertSee('IPTU por conta do(a)>')
            ->assertSee('aria-describedby="iptu-help"', false)
            ->assertSee('data-field-help="iptu-help"', false)
            ->assertSee('id="iptu-help" class="field-help-popover" popover', false)
            ->assertSee('Locador(a) ou Locatário?')->assertSee('cláusula expressa');
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $control = $html->getElementById('iptu');
        $this->assertSame('select', $control->tagName);
        $options = [];
        foreach ($control->getElementsByTagName('option') as $option) {
            if ($option->getAttribute('value') !== '') {
                $options[] = $option->getAttribute('value');
                $this->assertSame(ContractFields::IPTU_HELP[$option->getAttribute('value')], $option->getAttribute('title'));
            }
        }
        $this->assertSame(['Locador(a)', 'Locatário'], $options);
        $this->post('/contracts', $this->data(['iptu' => 'Outra responsabilidade']))->assertSessionHasErrors('iptu');
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_audits', 0);
        foreach ($options as $choice) {
            $this->post('/contracts', $this->data(['iptu' => $choice]))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($choice, Contract::latest('id')->firstOrFail()->iptu);
        }
    }

    public function test_iptu_preserves_legacy_value_until_an_explicit_valid_choice_is_made(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $contract->update(['iptu' => 'Responsabilidade conforme documento histórico']);
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertSee('value="Responsabilidade conforme documento histórico" selected', false);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Corrigir descrição', 'objeto' => 'Objeto corrigido'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Responsabilidade conforme documento histórico', $contract->fresh()->iptu);
        $this->put('/contracts/'.$contract->id, ['version' => 2, 'reason' => 'Manter dado histórico', 'iptu' => $contract->iptu])->assertRedirect()->assertSessionHasNoErrors();
        $this->put('/contracts/'.$contract->id, ['version' => 3, 'reason' => 'Tentativa de entrada livre', 'iptu' => 'Outra responsabilidade'])->assertSessionHasErrors('iptu');
        $this->assertSame(3, $contract->fresh()->version);
        $this->put('/contracts/'.$contract->id, ['version' => 3, 'reason' => 'Confirmar responsável por IPTU', 'iptu' => 'Locatário'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Locatário', $contract->fresh()->iptu);
    }

    public function test_compact_panel_has_only_department_and_single_line_type_and_creation_uses_session_fund(): void
    {
        $response = $this->get('/contracts/create')->assertOk();
        $html = new \DOMDocument;
        @$html->loadHTML($response->getContent());
        $xpath = new \DOMXPath($html);
        $this->assertSame(1, $xpath->query('//*[@id="tipo"]')->length);
        $type = $html->getElementById('tipo');
        $this->assertSame('select', $type->tagName);
        $this->assertTrue($type->hasAttribute('required'));
        $this->assertSame(1, $xpath->query('//section[contains(@class,"contract-administration-fields")]//*[@id="tipo"]')->length);
        $this->assertSame(0, $xpath->query('//section[contains(@class,"contract-administration-fields")]/h2')->length);
        $this->assertSame(0, $xpath->query('//section[contains(@class,"contract-administration-fields") and contains(@class,"surface")]')->length);
        $this->assertSame(0, $xpath->query('//*[@name="fund_id" or @name="administrative_status"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-contract-panels-scroll]')->length);
        $this->assertSame(count(collect(ContractFields::MAP)->groupBy('group')), $xpath->query('//*[@data-contract-panels-scroll]//*[@data-editor-section]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@data-contract-products-dialog]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-contract-products-open and @aria-controls="contract-products-dialog"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-contract-panels-scroll]//dialog[@data-contract-products-dialog]')->length);
        $response->assertSeeInOrder(['5. Vigência', '6. Financeiro', '7.Distrato/Paralização', '8.Produtos', 'Expandir Tudo']);
        $this->assertSame(0, $xpath->query('//*[@data-contract-panels-scroll]//*[@id="department_id" or @id="tipo"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-contract-panels-scroll]//nav[@aria-label="Seções do contrato"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-contract-panels-scroll]//button[@type="submit"]')->length);
        $input = $this->data();
        unset($input['fund_id']);
        $this->post('/contracts', $input)->assertRedirect()->assertSessionHasNoErrors();
        $contract = Contract::firstOrFail();
        $this->assertSame($this->fund, $contract->fund_id);
        $this->assertSame($this->department, $contract->department_id);
        $this->assertSame('Serviço', $contract->tipo);
        $this->assertSame('rascunho', $contract->administrative_status);
    }

    public function test_session_fund_cannot_be_overridden_and_non_municipality_rejects_department(): void
    {
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        $this->seedContractInspector($otherFund);
        $this->post('/contracts', $this->data(['fund_id' => $otherFund]))->assertForbidden();
        $this->withSession(['fund_id' => $otherFund]);
        $this->post('/contracts', $this->data(['fund_id' => $otherFund]))->assertSessionHasErrors('department_id');
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_audits', 0);
        $this->assertDatabaseCount('tb_N_Contrato', 0);
        $input = $this->data(['department_id' => null]);
        unset($input['fund_id']);
        $this->post('/contracts', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($otherFund, Contract::firstOrFail()->fund_id);
        $this->assertNull(Contract::firstOrFail()->department_id);
    }

    public function test_creation_without_session_fund_fails_and_edit_preserves_existing_fund_status_and_department(): void
    {
        $this->flushSession();
        $this->post('/contracts', $this->data())->assertSessionHasErrors('fund_id');
        $this->assertDatabaseCount('contracts', 0);
        $this->withSession(['fund_id' => $this->fund]);
        $contract = app(ContractWriter::class)->save($this->admin, $this->data(['administrative_status' => 'ativo']));
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        $otherDepartment = DB::table('contract_departments')->insertGetId(['name' => 'Outra Secretaria', 'name_key' => 'OUTRA SECRETARIA', 'raw_payload' => '{}']);
        $this->withSession(['fund_id' => $otherFund])->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertSee('name="department_id" disabled', false);
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Correção de tipo', 'tipo' => 'Outro tipo'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($this->fund, $contract->fresh()->fund_id);
        $this->assertSame($this->department, $contract->fresh()->department_id);
        $this->assertSame('ativo', $contract->fresh()->administrative_status);
        $this->put('/contracts/'.$contract->id, ['version' => 2, 'reason' => 'Tentativa de trocar secretaria', 'department_id' => $otherDepartment])->assertSessionHasErrors('department_id');
        $this->assertSame(2, $contract->fresh()->version);
    }

    public function test_new_contract_department_is_enabled_only_for_municipality_and_edit_keeps_existing_selection(): void
    {
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        $this->withSession(['fund_id' => $otherFund])->get('/contracts/create')->assertOk()
            ->assertSee('name="department_id" disabled', false);
        $this->withSession(['fund_id' => $this->fund])->get('/contracts/create')->assertOk()
            ->assertDontSee('name="department_id" disabled', false);
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertDontSee('data-department-dependency', false)
            ->assertDontSee('name="department_id" disabled', false)
            ->assertSee('value="'.$this->department.'" selected', false);
    }

    public function test_heading_shows_generated_number_after_save_and_supplier_and_instructions_are_positioned(): void
    {
        $this->withSession(['fund_id' => $this->fund])->get('/contracts/create')->assertOk()
            ->assertSeeInOrder(['fund-header-back', 'selected-fund-name', 'contract-context-heading'], false)
            ->assertDontSee('id="session-fund"', false)
            ->assertDontSee('Alterar Fundo')
            ->assertDontSee('aria-label="Abrir minha conta"', false)
            ->assertDontSee('workspace-date', false)
            ->assertSee('data-fit-fund-name', false)
            ->assertDontSee('breadcrumb-divider', false)
            ->assertSee('aria-label="Resumo do contrato e fornecedor"', false)
            ->assertSee('data-contract-summary-field="fornecedor_nome_snapshot"', false)
            ->assertSeeInOrder(['selected-fund-name', 'contract-heading-supplier', 'Nome / Razão Social', 'contract-context-heading', 'Nº do Contrato', 'Selecionar fornecedor cadastrado'], false)
            ->assertSee('value="" placeholder="N° do Contrato"', false)
            ->assertSee('data-contract-search-open', false)
            ->assertDontSee('Preencha as seções abaixo.')
            ->assertDontSee('Campos com')
            ->assertDontSee('Disponível somente para o Fundo')
            ->assertDontSee('Classificação interna, distinta da vigência calculada.')
            ->assertDontSee('Escolha como prefere preencher.')
            ->assertDontSee('name="fund_id"', false)->assertDontSee('name="administrative_status"', false)
            ->assertDontSee('<h2>Órgão e situação administrativa</h2>', false)
            ->assertSeeInOrder(['Órgão e situação administrativa', 'for="tipo"', 'Campo Obrigatório', 'data-editor-section="1"', 'for="fornecedor_documento_snapshot"', 'Campo Obrigatório', 'field-label-divider', 'Selecionar fornecedor cadastrado', 'id="fornecedor_documento_snapshot"'], false);
        $this->post('/contracts', $this->data(['end_fornec' => 'Rua do fornecedor']))->assertRedirect()->assertSessionHas('contract_created', true);
        $contract = Contract::firstOrFail();
        foreach (['/contracts/'.$contract->id, '/contracts/'.$contract->id.'/edit'] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('Rua do fornecedor');
            if (str_ends_with($url, '/edit')) {
                $response->assertDontSee('id="session-fund"', false)->assertDontSee('Alterar Fundo')
                    ->assertSeeInOrder(['selected-fund-name', 'contract-heading-supplier', 'Fornecedor fictício', 'contract-number-highlight'], false);
            } else {
                $response->assertDontSee('Alterar Fundo')->assertDontSee('id="session-fund"', false)
                    ->assertSeeInOrder(['selected-fund-name', 'contract-number-highlight', 'Fornecedor fictício'], false);
            }
            $this->assertStringContainsString('value="'.$contract->numero.'" placeholder="N° do Contrato"', $response->getContent());
            $heading = str_ends_with($url, '/edit') ? 'Editar contrato' : 'Novo contrato';
            $response->assertSee('<h1 class="sr-only">'.$heading.'</h1>', false)
                ->assertSee('<label for="contract-summary-number">Nº do Contrato</label>', false);
        }
    }

    public function test_heading_shows_only_supplier_name_and_escapes_it(): void
    {
        DB::table('suppliers')->where('document_key', '11222333000181')->update(['name' => '<script>Fornecedor</script>']);
        $contract = app(ContractWriter::class)->save($this->admin, $this->data([
            'fornecedor_nome_snapshot' => '<script>Fornecedor</script>', 'rg_fornec' => 'RG-PRIVADO', 'cpf_socio' => '52998224725',
        ]));
        $this->actingAs($this->regular());
        $html = $this->blade('<x-contract-summary :contract="$contract" :editable="true"/>', ['contract' => $contract]);
        $html->assertSee('&lt;script&gt;Fornecedor&lt;/script&gt;', false)->assertDontSee('<script>Fornecedor</script>', false)
            ->assertDontSee('RG-PRIVADO')->assertDontSee('52998224725')->assertDontSee('data-contract-summary-field="cpf_socio"', false);
    }

    public function test_document_icons_explain_save_requirement_and_pending_templates_without_submitting_contract(): void
    {
        $response = $this->get('/contracts/create')->assertOk()
            ->assertSeeInOrder(['contract-administration-row', 'Órgão e situação administrativa', 'Emissão de documentos do contrato', 'contract-section-navigation', 'expand-all-button', 'Expandir Tudo'])
            ->assertDontSee('<h2>Dados do contrato</h2>', false)
            ->assertDontSee('Preencher por etapas')
            ->assertSee('@click="all = true">Expandir Tudo</button>', false)
            ->assertSee('data-document-state="save-required"', false);
        foreach (['Contrato', 'Extrato', 'Publicação', 'Distrato'] as $label) {
            $response->assertSee('aria-label="Emitir '.$label.'"', false)
                ->assertSee('Salve o contrato antes de emitir '.$label.'.');
        }
        $this->assertSame(4, substr_count($response->getContent(), 'type="button" class="document-action"'));
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()
            ->assertSee('data-document-state="model-pending"', false)
            ->assertSee('A emissão de Contrato depende da configuração do modelo desse documento.')
            ->assertDontSee('Salve o contrato antes de emitir');
    }

    public function test_create_renders_sections_and_persists_native_contract_and_audit(): void
    {
        $this->get('/contracts/create')->assertOk()->assertSee('Novo contrato')->assertSee('Dados do veículo')->assertSee('Motivo do distrato');
        $response = $this->post('/contracts', $this->data(['dados_veiculo' => 'Veículo fictício', 'end_imovel' => 'Imóvel fictício', 'valor_acumulado' => '2500.0050']))
            ->assertRedirect();
        $contract = Contract::firstOrFail();
        $response->assertRedirect('/contracts/'.$contract->id);
        $this->assertNull($contract->legacy_id);
        $this->assertNull($contract->legacy_record_id);
        $this->assertSame('1908.6300', $contract->valor_total);
        $this->assertSame('2500.0050', $contract->valor_acumulado);
        $this->assertSame('Veículo fictício', $contract->dados_veiculo);
        $this->assertSame('Imóvel fictício', $contract->end_imovel);
        $this->assertSame($this->admin->id, $contract->created_by);
        $this->assertTrue($contract->ownership_resolved);
        $this->assertSame('MUNICIPIO DE NIQUELÂNDIA', $contract->fundo_nome_snapshot);
        $audit = ContractAudit::firstOrFail();
        $this->assertSame('created', $audit->action);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->get('/contracts/'.$contract->id)->assertOk()->assertSee('Histórico de alterações')->assertSee('R$ 1.908,63');
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertSee('Veículo fictício');
    }

    public function test_new_contract_rejects_invalid_documents_dates_money_and_missing_fields(): void
    {
        $this->post('/contracts', $this->data(['fornecedor_documento_snapshot' => '11111111111', 'data_contrato' => '0000-00-00',
            'valor_total' => '1,99999', 'objeto' => '']))->assertSessionHasErrors(['fornecedor_documento_snapshot', 'data_contrato', 'valor_total', 'objeto']);
        $this->post('/contracts', $this->data(['vigencia_fim_original' => '2026-09-30']))->assertSessionHasErrors('vigencia_fim_original');
        $this->postJson('/contracts', $this->data(['valor_total' => ['inválido'], 'fornecedor_documento_snapshot' => ['inválido']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['valor_total', 'fornecedor_documento_snapshot']);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public function test_update_preserves_omitted_fields_records_difference_and_requires_reason(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data(['dados_veiculo' => 'Preservar', 'cpf_gestor' => '11144477735']));
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'objeto' => 'Objeto alterado'])->assertSessionHasErrors('reason');
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'objeto' => 'Objeto alterado', 'reason' => 'Correção do objeto'])->assertRedirect('/contracts/'.$contract->id);
        $this->assertSame('Preservar', $contract->fresh()->dados_veiculo);
        $this->assertSame('11144477735', $contract->fresh()->cpf_gestor);
        $this->assertSame(2, $contract->fresh()->version);
        $audit = ContractAudit::latest('id')->firstOrFail();
        $this->assertSame('Objeto fictício', $audit->changes['objeto']['before']);
        $this->assertSame('Objeto alterado', $audit->changes['objeto']['after']);
        $this->assertArrayNotHasKey('dados_veiculo', $audit->changes);
        $this->assertSame('Correção do objeto', $audit->reason);
    }

    public function test_stale_version_returns_conflict_and_preserves_typed_values_without_overwriting(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'objeto' => 'Primeira alteração', 'reason' => 'Primeiro ajuste'])->assertRedirect();
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'objeto' => 'Segunda alteração', 'reason' => 'Segundo ajuste'])
            ->assertStatus(409)->assertSee('Recarregar contrato')->assertSee('Segunda alteração')->assertSee('disabled', false);
        $this->assertSame('Primeira alteração', $contract->fresh()->objeto);
        $this->assertDatabaseCount('contract_audits', 2);
    }

    public function test_regular_user_can_write_only_inside_authorized_scope_even_by_direct_requests(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $otherFund = DB::table('contract_funds')->insertGetId(['legacy_id' => 2, 'name' => 'Fundo B', 'name_key' => 'FUNDO B', 'raw_payload' => '{}']);
        $user = $this->regular();
        $this->actingAs($user)->get('/contracts/create')->assertOk()->assertDontSee('Fundo B');
        $this->post('/contracts', $this->data(['numero' => '002/26']))->assertRedirect();
        $this->get('/contracts/'.$contract->id.'/edit')->assertOk();
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'objeto' => 'Edição autorizada', 'reason' => 'Ajuste permitido'])->assertRedirect();
        $this->post('/contracts', $this->data(['fund_id' => $otherFund]))->assertForbidden();
        $this->put('/contracts/'.$contract->id, ['version' => 2, 'fund_id' => $otherFund, 'reason' => 'Mover para outro fundo'])->assertForbidden();
        $this->post('/contracts', $this->data(['department_id' => null]))->assertForbidden();
        $user->scopes()->detach();
        $this->flushSession();
        $this->get('/contracts/create')->assertForbidden();
        $this->get('/contracts/'.$contract->id.'/edit')->assertForbidden();
        $this->put('/contracts/'.$contract->id, ['version' => 2, 'objeto' => 'Bloqueado', 'reason' => 'Sem escopo válido'])->assertForbidden();
        $this->assertSame($this->fund, $contract->fresh()->fund_id);
        $this->assertDatabaseCount('contracts', 2);
    }

    public function test_personal_fields_remain_masked_and_cannot_be_overwritten_by_regular_user(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data(['cpf_gestor' => '11144477735', 'fornecedor_documento_snapshot' => '52998224725']));
        $this->actingAs($this->regular())->get('/contracts/'.$contract->id.'/edit')->assertOk()->assertDontSee('11144477735')->assertDontSee('52998224725');
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'cpf_gestor' => '99999999999', 'reason' => 'Tentativa de alteração'])->assertSessionHasErrors('cpf_gestor');
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'objeto' => 'Alteração permitida', 'reason' => 'Objeto corrigido'])->assertRedirect();
        $this->assertSame('11144477735', $contract->fresh()->cpf_gestor);
        $this->get('/contracts/'.$contract->id)->assertDontSee('Histórico de alterações');
    }

    public function test_legacy_edit_preserves_source_incomplete_fields_and_unchanged_historical_snapshots(): void
    {
        $contract = app(ContractWriter::class)->save($this->admin, $this->data());
        $run = ImportRun::create(['source_hash' => str_repeat('b', 64), 'status' => 'completed', 'started_at' => now()]);
        $record = LegacyRecord::create(['source_table' => 'TB_Contratos', 'legacy_id' => '91', 'source_hash' => str_repeat('c', 64),
            'raw_payload' => ['Fundo' => 'Grafia histórica', 'Data_Cont' => '0000-00-00'], 'import_run_id' => $run->id]);
        $contract->update(['legacy_id' => 91, 'legacy_record_id' => $record->id, 'fundo_nome_snapshot' => 'Grafia histórica',
            'data_contrato' => null, 'valor_total' => null, 'fornecedor_documento_snapshot' => '',
            'vigencia_inicio' => '2026-10-01', 'vigencia_fim_original' => '2026-09-01', 'vigencia_em_revisao' => true]);
        $contract->refresh();
        $input = ['version' => $contract->version, 'reason' => 'Ajuste de descrição histórica', 'fund_id' => $this->fund, 'department_id' => $this->department];
        foreach (ContractFields::MAP as $definition) {
            $column = $definition['column'];
            if (! in_array($column, ContractFields::DERIVED)) {
                $input[$column] = $definition['type'] === 'date' ? $contract->$column?->format('Y-m-d') : $contract->$column;
            }
        }
        $input['objeto'] = 'Descrição corrigida';
        $this->put('/contracts/'.$contract->id, $input)->assertRedirect('/contracts/'.$contract->id);
        $contract->refresh();
        $this->assertSame('Grafia histórica', $contract->fundo_nome_snapshot);
        $this->assertNull($contract->data_contrato);
        $this->assertNull($contract->valor_total);
        $this->assertSame(91, $contract->legacy_id);
        $this->assertSame(['Fundo' => 'Grafia histórica', 'Data_Cont' => '0000-00-00'], $record->fresh()->raw_payload);
        $this->assertTrue($contract->vigencia_em_revisao);
        $this->put('/contracts/'.$contract->id, ['version' => $contract->version, 'legacy_id' => 999, 'reason' => 'Tentativa de alterar origem'])->assertSessionHasErrors('legacy_id');
    }

    public function test_repeated_forms_receive_distinct_generated_numbers_and_actor_cannot_be_forged(): void
    {
        $first = app(ContractWriter::class)->save($this->admin, $this->data());
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('contract_audits', 1);
        $this->post('/contracts', $this->data(['duplicate_confirmation' => '1', 'created_by' => 999, 'updated_by' => 999, 'ownership_resolved' => false]))
            ->assertRedirect()->assertSessionMissing('warning');
        $contract = Contract::latest('id')->firstOrFail();
        $this->assertNotSame($first->numero, $contract->numero);
        $this->assertSame(2, $contract->number_sequence);
        $this->assertSame('2', $contract->cont_inteiro);
        $this->assertSame($this->admin->id, $contract->created_by);
        $this->assertTrue($contract->ownership_resolved);
        $this->assertDatabaseCount('contracts', 2);
        $this->assertArrayNotHasKey('duplicate_confirmation', ContractAudit::latest('id')->first()->changes);
    }

    public function test_preventive_duplicate_check_does_not_block_distinct_processes_or_expose_other_departments(): void
    {
        app(ContractWriter::class)->save($this->admin, $this->data());
        $this->post('/contracts', $this->data(['processo' => 'OUTRO-PROCESSO']))->assertRedirect()->assertSessionMissing('warning');
        $otherDepartment = DB::table('contract_departments')->insertGetId(['name' => 'Outra secretaria', 'name_key' => 'OUTRA SECRETARIA', 'raw_payload' => '{}']);
        $this->seedContractInspector($this->fund, $otherDepartment);
        $user = $this->regular();
        app(ContractWriter::class)->save($this->admin, $this->data(['numero' => 'HIDDEN/26', 'department_id' => $otherDepartment]));
        $this->actingAs($user)->post('/contracts', $this->data(['numero' => 'HIDDEN/26']))->assertRedirect()->assertSessionMissing('warning');
        $this->assertDatabaseCount('contracts', 4);
        $this->post('/contracts', $this->data(['numero' => 'HIDDEN/26', 'duplicate_confirmation' => 'false']))->assertSessionHasErrors('duplicate_confirmation');
        $this->assertDatabaseCount('contracts', 4);
    }

    public function test_audit_failure_rolls_back_the_contract(): void
    {
        ContractAudit::creating(fn () => throw new RuntimeException('Falha de auditoria simulada.'));
        try {
            app(ContractWriter::class)->save($this->admin, $this->data());
            $this->fail('A gravação deve falhar junto com a auditoria.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha de auditoria simulada.', $exception->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_audits', 0);
    }
}
