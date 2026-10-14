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
        $this->actingAs($this->admin);
        $this->withSession(['fund_id' => $this->fund]);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['numero' => '001/26', 'processo' => 'PROC-001', 'fornecedor_nome_snapshot' => 'Fornecedor fictício',
            'fornecedor_documento_snapshot' => '11.222.333/0001-81', 'objeto' => 'Objeto fictício', 'tipo' => 'Serviço',
            'data_contrato' => '2026-10-01', 'vigencia_inicio' => '2026-10-01', 'vigencia_fim_original' => '2027-10-01',
            'valor_total' => '1.908,6300', 'modalidade' => 'Pregão', 'n_licit' => '001/2026', 'fund_id' => $this->fund, 'department_id' => $this->department], $extra);
    }

    public function test_identification_form_hides_unused_controls_and_preserves_their_historical_values(): void
    {
        $historical = ['proc_edital' => 'PROC-LEGADO', 'n_edital' => 'EDITAL-LEGADO', 'data_licit' => '2026-09-01', 'n_solicit' => 'SOL-LEGADA'];
        $response = $this->get('/contracts/create')->assertOk();
        foreach (array_keys($historical) as $column) {
            $response->assertDontSee('name="'.$column.'"', false);
        }
        $response->assertSee('id="modalidade"', false)->assertSee('id="n_licit"', false);
        $contract = app(ContractWriter::class)->save($this->admin, $this->data($historical));
        $response = $this->get('/contracts/'.$contract->id.'/edit')->assertOk();
        foreach (array_keys($historical) as $column) {
            $response->assertDontSee('name="'.$column.'"', false);
        }
        $this->put('/contracts/'.$contract->id, ['version' => 1, 'reason' => 'Correção de descrição', 'objeto' => 'Objeto corrigido'])->assertRedirect();
        foreach ($historical as $column => $value) {
            $actual = $column === 'data_licit' ? $contract->fresh()->$column->format('Y-m-d') : $contract->fresh()->$column;
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
        if ($scope) {
            $scope = AccessScope::create(['scope_key' => hash('sha256', 'scope'), 'fund' => 'Fundo A', 'department' => 'Secretaria A',
                'fund_id' => $this->fund, 'department_id' => $this->department]);
            $user->scopes()->attach($scope);
        }

        return $user;
    }

    public function test_iptu_has_two_choices_and_rejects_other_new_values(): void
    {
        $response = $this->get('/contracts/create')->assertOk()->assertSee('IPTU por conta do(a)>');
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
        $this->assertSame('input', $type->tagName);
        $this->assertTrue($type->hasAttribute('required'));
        $this->assertSame(1, $xpath->query('//section[contains(@class,"contract-administration-fields")]//*[@id="tipo"]')->length);
        $this->assertSame(0, $xpath->query('//section[contains(@class,"contract-administration-fields")]/h2')->length);
        $this->assertSame(0, $xpath->query('//section[contains(@class,"contract-administration-fields") and contains(@class,"surface")]')->length);
        $this->assertSame(0, $xpath->query('//*[@name="fund_id" or @name="administrative_status"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-contract-panels-scroll]')->length);
        $this->assertSame(count(collect(ContractFields::MAP)->groupBy('group')), $xpath->query('//*[@data-contract-panels-scroll]//*[@data-editor-section]')->length);
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
        $this->withSession(['fund_id' => $this->fund])->get('/contracts/create')->assertOk()->assertSee('Gerado ao salvar')
            ->assertSeeInOrder(['fund-header-back', 'selected-fund-name', 'contract-context-heading'], false)
            ->assertDontSee('id="session-fund"', false)
            ->assertDontSee('Alterar Fundo')
            ->assertDontSee('aria-label="Abrir minha conta"', false)
            ->assertDontSee('workspace-date', false)
            ->assertSee('data-fit-fund-name', false)
            ->assertDontSee('breadcrumb-divider', false)
            ->assertSee('aria-label="Resumo do contrato e fornecedor"', false)
            ->assertSee('data-contract-summary-field="fornecedor_nome_snapshot"', false)
            ->assertSeeInOrder(['contract-context-heading', '<h1>Novo contrato', 'Não informado', 'Selecionar fornecedor cadastrado'], false)
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
                    ->assertSeeInOrder(['contract-number-highlight', 'Fornecedor fictício'], false);
            } else {
                $response->assertSeeInOrder(['Alterar Fundo', 'contract-number-highlight', 'Fornecedor fictício'], false);
            }
            $this->assertStringContainsString('<strong class="contract-number-highlight">'.$contract->numero.'</strong>', $response->getContent());
            $heading = str_ends_with($url, '/edit') ? 'Editar contrato' : 'Novo contrato';
            $response->assertSee('<h1>'.$heading.' <strong class="contract-number-highlight">', false);
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
        $otherDepartment = DB::table('contract_departments')->insertGetId(['name' => 'Outra secretaria', 'name_key' => 'OUTRA', 'raw_payload' => '{}']);
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
