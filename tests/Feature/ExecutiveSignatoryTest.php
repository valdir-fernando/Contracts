<?php

namespace Tests\Feature;

use App\Infrastructure\Documents\ContractPublicationPdf;
use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Documents\PublicationDocumentTemplate;
use App\Infrastructure\Documents\SupplyContractExtract;
use App\Infrastructure\Documents\SupplyExtractTemplate;
use App\Infrastructure\Managers\ManagerRegistration;
use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\LockActor;
use App\Infrastructure\Security\SelectedFund;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExecutiveSignatoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $fund;

    private int $mayor;

    private int $delegate;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'Synthetic123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'MUNICIPIO DE NIQUELÂNDIA', 'name_key' => ManagerSignatory::MUNICIPAL_FUND, 'raw_payload' => '{}']);
        $this->mayor = $this->manager('Prefeito exemplo', 'Prefeito Municipal');
        $this->delegate = $this->manager('Secretária exemplo', 'Gestora do Executivo Municipal');
        $this->contract = Contract::create(['numero' => '001/26', 'tipo' => 'Fornecimento', 'fund_id' => $this->fund,
            'ownership_resolved' => true, 'fundo_nome_snapshot' => 'MUNICIPIO DE NIQUELÂNDIA',
            'nome_gestor' => 'Gestor histórico', 'cargo_gestor' => 'Cargo histórico', 'quality_issues' => []]);
        $this->contract->refresh();
        $this->actingAs($this->admin)->withSession(['fund_id' => $this->fund]);
    }

    private function manager(string $name, string $role, int $signature = 0): int
    {
        return DB::table('tb_Gestor')->insertGetId(['name' => $name, 'name_key' => $name,
            'fund_name_key' => ManagerSignatory::MUNICIPAL_FUND,
            'raw_payload' => json_encode(['Nome_Gestor' => $name, 'Cargo' => $role, 'Assinatura' => $signature,
                'CPF_Gestor' => $role === 'Prefeito Municipal' ? '11144477735' : '52998224725', 'RG_Gestor' => 'RG privado'])]);
    }

    private function signature(int $id, int $signature): void
    {
        $record = DB::table('tb_Gestor')->find($id);
        $raw = json_decode($record->raw_payload, true);
        $raw['Assinatura'] = $signature;
        DB::table('tb_Gestor')->where('id', $id)->update(['raw_payload' => json_encode($raw)]);
    }

    private function update(int $id, array $extra = []): array
    {
        $record = DB::table('tb_Gestor')->find($id);

        return $extra + ['fund_id' => $this->fund, 'Nome_Gestor' => $record->name, 'CPF_Gestor' => json_decode($record->raw_payload, true)['CPF_Gestor'],
            'revision' => app(ManagerRegistration::class)->revision($record)];
    }

    public function test_mayor_is_fallback_and_delegate_is_selected_without_changing_contract_history(): void
    {
        $resolver = app(ManagerSignatory::class);
        $this->assertSame('Prefeito exemplo', $resolver->forContract($this->contract)['Nome_Gestor']);
        $this->put('/managers/'.$this->delegate, $this->update($this->delegate, ['Assinatura' => 1]))->assertRedirect();
        $this->assertSame('SECRETÁRIA EXEMPLO', $resolver->forContract($this->contract)['Nome_Gestor']);
        $defaults = app(SelectedFund::class)->defaults($this->admin, $this->fund);
        $this->assertSame('SECRETÁRIA EXEMPLO', $defaults['nome_gestor']);
        $this->assertSame('52998224725', $defaults['cpf_gestor']);
        $this->assertSame('Gestor histórico', $this->contract->fresh()->nome_gestor);
        $this->put('/managers/'.$this->delegate, $this->update($this->delegate, ['Assinatura' => 0]))->assertRedirect();
        $this->assertSame('Prefeito exemplo', $resolver->forContract($this->contract)['Nome_Gestor']);
    }

    public function test_second_signer_is_rejected_even_for_another_administrator_and_omission_preserves_active_flag(): void
    {
        $this->signature($this->delegate, 1);
        $this->put('/managers/'.$this->delegate, $this->update($this->delegate))->assertRedirect();
        $other = User::create(['name' => 'Outro', 'username' => 'OUTRO', 'password' => 'Synthetic123', 'user_type' => 'Administrador']);
        $this->actingAs($other)->put('/managers/'.$this->mayor, $this->update($this->mayor, ['Assinatura' => 1]))->assertSessionHasErrors('Assinatura');
        $raw = json_decode(DB::table('tb_Gestor')->find($this->mayor)->raw_payload, true);
        $this->assertEquals(0, $raw['Assinatura']);
    }

    public function test_other_funds_cannot_activate_flag_and_moving_manager_clears_it(): void
    {
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Saúde', 'name_key' => 'FUNDO SAUDE', 'raw_payload' => '{}']);
        $this->put('/managers/'.$this->delegate, $this->update($this->delegate, ['fund_id' => $otherFund, 'Assinatura' => 1]))->assertSessionHasErrors('Assinatura');
        $this->signature($this->delegate, 1);
        $this->put('/managers/'.$this->delegate, $this->update($this->delegate, ['fund_id' => $otherFund]))->assertRedirect();
        $raw = json_decode(DB::table('tb_Gestor')->find($this->delegate)->raw_payload, true);
        $this->assertEquals(0, $raw['Assinatura']);
        $this->assertSame('Prefeito exemplo', app(ManagerSignatory::class)->forContract($this->contract)['Nome_Gestor']);
        $this->get('/catalogs/list/manager-fund?edit='.$this->delegate)->assertOk()
            ->assertDontSee('x-show="municipal.includes(fund)"', false)->assertSee(':disabled="!municipal.includes(fund) || !signatureAvailable"', false);
    }

    public function test_signature_control_is_always_present_and_available_only_when_no_other_signer_is_active(): void
    {
        $url = '/catalogs/list/manager-fund';
        $this->get($url)->assertOk()->assertViewHas('signatureAvailable', true)
            ->assertSee('id="Assinatura"', false)->assertDontSee('x-show="municipal.includes(fund)"', false);
        $this->get($url.'?edit='.$this->delegate)->assertOk()->assertViewHas('signatureAvailable', true);
        $this->signature($this->delegate, 1);
        $this->get($url)->assertOk()->assertViewHas('signatureAvailable', false)->assertSee('id="Assinatura"', false);
        $this->get($url.'?edit='.$this->mayor)->assertOk()->assertViewHas('signatureAvailable', false);
        $this->get($url.'?edit='.$this->delegate)->assertOk()->assertViewHas('signatureAvailable', true);
        $this->put('/managers/'.$this->delegate, $this->update($this->delegate, ['Assinatura' => 0]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get($url.'?edit='.$this->mayor)->assertOk()->assertViewHas('signatureAvailable', true);
        $this->signature($this->delegate, 1);
        DB::table('tb_Gestor')->where('id', $this->delegate)->update(['deleted_at' => now()]);
        $this->get($url)->assertOk()->assertViewHas('signatureAvailable', true);
    }

    public function test_deleted_delegate_falls_back_to_mayor_and_other_fund_contract_keeps_its_snapshot(): void
    {
        $this->signature($this->delegate, 1);
        $this->delete('/managers/'.$this->delegate, ['revision' => $this->update($this->delegate)['revision'], 'confirmation' => 1])->assertRedirect();
        $this->assertSame('Prefeito exemplo', app(ManagerSignatory::class)->forContract($this->contract)['Nome_Gestor']);
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Saúde', 'name_key' => 'FUNDO SAUDE', 'raw_payload' => '{}']);
        $this->contract->fund_id = $other;
        $this->assertSame('Gestor histórico', app(ManagerSignatory::class)->forContract($this->contract)['Nome_Gestor']);
    }

    public function test_ambiguous_legacy_flags_block_emission_instead_of_choosing_arbitrarily(): void
    {
        $this->signature($this->mayor, 1);
        $this->signature($this->delegate, 1);
        $this->expectException(HttpException::class);
        app(ManagerSignatory::class)->forContract($this->contract);
    }

    public function test_missing_mayor_blocks_fallback_instead_of_using_a_secretary_without_delegation(): void
    {
        DB::table('tb_Gestor')->where('id', $this->mayor)->update(['deleted_at' => now()]);
        $this->expectException(HttpException::class);
        app(ManagerSignatory::class)->forContract($this->contract);
    }

    public function test_new_amendment_uses_current_signer_even_when_client_sends_another_name(): void
    {
        $this->signature($this->delegate, 1);
        $this->post('/contracts/'.$this->contract->id.'/amendments', ['contract_version' => $this->contract->version,
            'reason' => 'Cadastro de aditivo para teste', 'fields' => ['N_ADT' => '001', 'Data_ADT' => '2026-10-18',
                'Proc_ADT' => 'PROC-001', 'Tipo_ADT' => 'Prorrogação', 'Motivo_ADT' => 'Motivo documental', 'Gestor' => 'Nome enviado pelo cliente']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Secretária exemplo', ContractAmendment::sole()->payload['Gestor']);
        $this->assertSame('Gestor histórico', $this->contract->fresh()->nome_gestor);
    }

    private function extract(?\Closure $during = null): SupplyContractExtract
    {
        return new class(app(LockActor::class), app(DocumentSettings::class), app(SupplyExtractTemplate::class), $during) extends SupplyContractExtract
        {
            public string $html = '';

            public function __construct($actors, $settings, $template, private ?\Closure $during)
            {
                parent::__construct($actors, $settings, $template);
            }

            protected function render(string $html): string
            {
                $this->html = $html;
                if ($this->during) {
                    ($this->during)();
                }

                return '%PDF-synthetic';
            }
        };
    }

    private function publication(?\Closure $during = null): ContractPublicationPdf
    {
        return new class(app(LockActor::class), app(DocumentSettings::class), app(PublicationDocumentTemplate::class), $during) extends ContractPublicationPdf
        {
            public string $html = '';

            public function __construct($actors, $settings, $template, private ?\Closure $during)
            {
                parent::__construct($actors, $settings, $template);
            }

            protected function render(string $html): string
            {
                $this->html = $html;
                if ($this->during) {
                    ($this->during)();
                }

                return '%PDF-synthetic';
            }
        };
    }

    public function test_extract_and_publication_use_current_signer_and_mayor_fallback(): void
    {
        foreach ([0 => 'Prefeito exemplo', 1 => 'Secretária exemplo'] as $flag => $name) {
            $this->signature($this->delegate, $flag);
            foreach ([$this->extract(), $this->publication()] as $renderer) {
                $renderer->generate($this->admin, $this->contract->id, $this->contract->version);
                $this->assertStringContainsString($name, $renderer->html);
                $this->assertStringNotContainsString('Gestor histórico', $renderer->html);
            }
        }
    }

    public function test_signature_change_during_rendering_prevents_delivery_and_audit_for_both_documents(): void
    {
        foreach (['extract', 'publication'] as $kind) {
            $this->signature($this->delegate, 1);
            $renderer = $this->{$kind}(fn () => $this->signature($this->delegate, 0));
            try {
                $renderer->generate($this->admin, $this->contract->id, $this->contract->version);
                $this->fail('A changed signatory must prevent delivery.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_current_signer_documents_keep_cpf_masked_for_regular_users(): void
    {
        $this->signature($this->delegate, 1);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'Synthetic123']);
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $scope = AccessScope::create(['scope_key' => 'municipal-scope', 'fund_id' => $this->fund, 'fund' => 'MUNICIPIO DE NIQUELÂNDIA']);
        $user->scopes()->attach($scope);
        foreach ([$this->extract(), $this->publication()] as $renderer) {
            $renderer->generate($user, $this->contract->id, $this->contract->version);
            $this->assertStringContainsString('Secretária exemplo', $renderer->html);
            $this->assertStringNotContainsString('529.982.247-25', $renderer->html);
            $this->assertStringNotContainsString('RG privado', $renderer->html);
        }
    }

    public function test_missing_fallback_during_rendering_is_a_conflict_without_audit(): void
    {
        $renderer = $this->extract(fn () => DB::table('tb_Gestor')->where('id', $this->mayor)->update(['deleted_at' => now()]));
        try {
            $renderer->generate($this->admin, $this->contract->id, $this->contract->version);
            $this->fail('A missing fallback must prevent delivery.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('activity_events', 0);
    }
}
