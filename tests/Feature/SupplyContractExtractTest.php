<?php

namespace Tests\Feature;

use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Documents\SupplyContractExtract;
use App\Infrastructure\Documents\SupplyExtractTemplate;
use App\Infrastructure\Security\LockActor;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplyContractExtractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Contract $contract;

    public static function extractTypes(): array
    {
        return [['Fornecimento'], ['Locação de Imóvel']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo de teste', 'name_key' => 'FUNDO DE TESTE', 'raw_payload' => '{}']);
        $this->contract = Contract::create(['numero' => '015/26', 'processo' => '202600001', 'modalidade' => 'Pregão', 'n_licit' => '012/26',
            'tipo' => 'Fornecimento', 'fund_id' => $fund, 'ownership_resolved' => true, 'fundo_nome_snapshot' => 'Fundo de teste',
            'fornecedor_nome_snapshot' => 'Fornecedor fictício', 'fornecedor_documento_snapshot' => '52998224725',
            'objeto' => 'Fornecimento de materiais de teste', 'vigencia' => '12 meses', 'vigencia_inicio' => '2026-01-01',
            'vigencia_fim_original' => '2026-12-31', 'vigencia_fim_atual' => '2027-02-28',
            'valor_total' => '2530.9200', 'valor_acumulado' => '9999.0000', 'ext_total' => 'Dois mil quinhentos e trinta reais e noventa e dois centavos',
            'nome_gestor' => 'Gestor fictício', 'cpf_gestor' => '11144477735', 'cargo_gestor' => 'Gestor do Fundo',
            'data_contrato' => '2026-01-02', 'quality_issues' => [], 'rg_fornec' => 'PRIVATE RG', 'end_fornec' => 'PRIVATE ADDRESS']);
        $this->actingAs($this->admin)->withSession(['fund_id' => $fund]);
    }

    private function url(?int $version = null): string
    {
        return '/contracts/'.$this->contract->id.'/extract?version='.($version ?? $this->contract->fresh()->version);
    }

    private function renderer(?\Closure $during = null): SupplyContractExtract
    {
        $renderer = new class(app(LockActor::class), app(DocumentSettings::class), app(SupplyExtractTemplate::class), $during, DB::transactionLevel()) extends SupplyContractExtract
        {
            public string $html = '';

            public function __construct($actors, $settings, $template, private ?\Closure $during, private int $outerTransactionLevel)
            {
                parent::__construct($actors, $settings, $template);
            }

            protected function render(string $html): string
            {
                if (DB::transactionLevel() !== $this->outerTransactionLevel) {
                    throw new \RuntimeException('Rendering must be outside the transaction');
                }
                $this->html = $html;
                if ($this->during) {
                    ($this->during)();
                }

                return parent::render($html);
            }
        };
        app()->instance(SupplyContractExtract::class, $renderer);

        return $renderer;
    }

    public function test_supplied_word_model_generates_private_pdf_with_all_fields_and_audit(): void
    {
        $renderer = $this->renderer();
        $response = $this->get($this->url())->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(5000, strlen($response->getContent()));
        // A short extract must fit on one page; merged Word columns previously overflowed.
        $this->assertSame(1, preg_match_all('~/Type\s*/Page\b~', $response->getContent()));
        foreach (['ADM: 2025/2028', 'EXTRATO CONTRATO 015/26', 'Fundo de teste', '202600001 - Pregão Nº. 012/26',
            'Fornecedor fictício', 'CPF: 529.982.247-25', '31/12/2026', 'R$ 2.530,92', 'Dois mil quinhentos',
            'Gestor fictício', 'CPF: 111.444.777-35', 'Gestor do Fundo', '02/01/2026', 'data:image/png;base64,'] as $text) {
            $this->assertStringContainsString($text, $renderer->html);
        }
        foreach (['#Contrato', '#CNPJ', '#ADM', '#Início', 'PRIVATE RG', 'PRIVATE ADDRESS', '9.999,00'] as $text) {
            $this->assertStringNotContainsString($text, $renderer->html);
        }
        $audit = ActivityEvent::sole();
        $this->assertSame('contract_extract_generated', $audit->action);
        $this->assertSame(hash('sha256', $response->getContent()), $audit->metadata['sha256']);
        $this->assertSame('2025/2028', $audit->metadata['administration']);
        $this->assertSame($this->contract->id, $audit->metadata['contract_id']);
    }

    public function test_cnpj_label_fallback_date_current_administration_and_escaping(): void
    {
        $this->contract->update(['fornecedor_documento_snapshot' => '11222333000181', 'vigencia_fim_atual' => null,
            'objeto' => '<script>alert(1)</script> & materiais']);
        DB::table('document_settings')->where('id', 1)->update(['administration_start_year' => 2029, 'administration_end_year' => 2032]);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $this->assertStringContainsString('CNPJ: 11.222.333/0001-81', $renderer->html);
        $this->assertStringContainsString('31/12/2026', $renderer->html);
        $this->assertStringContainsString('ADM: 2029/2032', $renderer->html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; materiais', $renderer->html);
        $this->assertStringNotContainsString('<script>', $renderer->html);
    }

    #[DataProvider('extractTypes')]
    public function test_regular_user_projection_keeps_personal_fields_masked_and_scope_enforced(string $type): void
    {
        $this->contract->update(['tipo' => $type]);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $scope = AccessScope::create(['scope_key' => 'extract-scope', 'fund_id' => $this->contract->fund_id, 'fund' => 'Fundo de teste']);
        $user->scopes()->attach($scope);
        $renderer = $this->renderer();
        $this->actingAs($user)->get($this->url())->assertOk();
        $this->assertStringContainsString('CPF: •••4725', $renderer->html);
        $this->assertStringNotContainsString('529.982.247-25', $renderer->html);
        $this->assertStringNotContainsString('111.444.777-35', $renderer->html);
        $user->scopes()->detach();
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_other_types_stale_versions_and_missing_version_cannot_emit(): void
    {
        $this->get($this->url($this->contract->fresh()->version + 1))->assertStatus(409);
        $this->get('/contracts/'.$this->contract->id.'/extract')->assertSessionHasErrors('version');
        $this->contract->update(['tipo' => 'Locação de Veículo']);
        $this->get($this->url())->assertStatus(422);
        $this->assertDatabaseCount('activity_events', 0);
        $this->contract->delete();
        $this->get($this->url(1))->assertNotFound();
    }

    #[DataProvider('extractTypes')]
    public function test_revoking_issuance_during_rendering_prevents_delivery_and_audit(string $type): void
    {
        $this->contract->update(['tipo' => $type]);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $user->catalog_permissions = ['contract-issuance'];
        $user->save();
        $scope = AccessScope::create(['scope_key' => 'issuer-scope', 'fund_id' => $this->contract->fund_id, 'fund' => 'Fundo de teste']);
        $user->scopes()->attach($scope);
        $this->renderer(fn () => DB::table('users')->where('id', $user->id)->update(['catalog_permissions' => '[]']));
        $this->actingAs($user)->get($this->url())->assertForbidden();
        $this->assertDatabaseCount('activity_events', 0);
    }

    #[DataProvider('extractTypes')]
    public function test_change_during_rendering_prevents_delivery_and_audit(string $type): void
    {
        $this->contract->update(['tipo' => $type]);
        $this->renderer(fn () => DB::table('contracts')->where('id', $this->contract->id)->increment('version'));
        $this->get($this->url())->assertStatus(409);
        $this->assertDatabaseCount('activity_events', 0);
        $this->renderer(fn () => DB::table('document_settings')->where('id', 1)->increment('version'));
        $this->get($this->url())->assertStatus(409);
        $this->assertDatabaseCount('activity_events', 0);
        $this->renderer(fn () => DB::table('users')->where('id', $this->admin->id)->increment('session_version'));
        $this->get($this->url())->assertStatus(403);
        $this->assertDatabaseCount('activity_events', 0);
    }

    #[DataProvider('extractTypes')]
    public function test_failed_audit_does_not_deliver_the_pdf(string $type): void
    {
        $this->contract->update(['tipo' => $type]);
        ActivityEvent::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            $this->get($this->url())->assertStatus(500);
            $this->assertDatabaseCount('activity_events', 0);
        } finally {
            ActivityEvent::flushEventListeners();
        }
    }

    public function test_extract_button_is_enabled_only_for_saved_supply_contracts(): void
    {
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertSee('data-extract-url=', false)->assertSee('data-document-state="ready"', false);
        $this->contract->tipo = 'Serviços';
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])->assertDontSee('data-extract-url=', false);
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => new Contract(['tipo' => 'Fornecimento'])])
            ->assertSee('Salve o contrato antes de emitir Extrato.')->assertDontSee('data-extract-url=', false);
    }

    public function test_property_lease_word_generates_private_extract_using_saved_monthly_and_property_fields(): void
    {
        $this->contract->update(['tipo' => 'Locação de Imóvel', 'end_imovel' => 'Rua do imóvel, 123', 'instalacoes' => 'Secretaria de teste',
            'vl_parcial' => '210.9100', 'ext_parcial' => 'Duzentos e dez reais e noventa e um centavos', 'objeto' => 'OBJETO GENERICO NAO USADO']);
        $before = $this->contract->fresh()->getRawOriginal();
        $renderer = $this->renderer();
        $response = $this->get($this->url().'&model=supply&tipo=Fornecimento')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', 'inline; filename="extrato-locacao-imovel-'.$this->contract->id.'.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame(1, preg_match_all('~/Type\s*/Page\b~', $response->getContent()));
        foreach (['Locação de Imóvel', 'Locador(a):', 'Fornecedor fictício', 'Rua do imóvel, 123', 'Secretaria de teste',
            'Duzentos e dez reais', 'R$ 210,91', 'R$ 2.530,92', '31/12/2026', '02/01/2026', 'Fundo de teste', 'data:image/png;base64,'] as $value) {
            $this->assertStringContainsString($value, $renderer->html);
        }
        foreach (['#FUNDO', '#NomeLocador', '#EndImovel', '#VLMensal', '#ExtMensal', '28/02/2027', 'OBJETO GENERICO NAO USADO', '9.999,00', 'PRIVATE RG', 'PRIVATE ADDRESS'] as $value) {
            $this->assertStringNotContainsString($value, $renderer->html);
        }
        $audit = ActivityEvent::sole();
        $this->assertSame('property-lease', $audit->metadata['model']);
        $this->assertSame(hash_file('sha256', app(SupplyExtractTemplate::class)->path('property-lease')), $audit->metadata['model_sha256']);
        $this->assertSame(hash('sha256', $response->getContent()), $audit->metadata['sha256']);
        $this->assertSame($before, $this->contract->fresh()->getRawOriginal());
        $this->blade('<x-contract-document-actions :contract="$contract"/>', ['contract' => $this->contract])
            ->assertSee('data-extract-url=', false)->assertSee('Abrir extrato de locação de imóvel em PDF.')
            ->assertSee('data-contract-document-url=', false)->assertSee('Abrir contrato de locação de imóvel em PDF.');
    }

    public function test_property_lease_handles_missing_fields_cnpj_escaping_and_long_property_text(): void
    {
        $this->contract->update(['tipo' => 'Locação de Imóvel', 'fornecedor_documento_snapshot' => '11222333000181',
            'end_imovel' => '<script>imovel</script> & rua', 'instalacoes' => str_repeat('Destinação do imóvel. ', 800),
            'vl_parcial' => null, 'ext_parcial' => null, 'vigencia_fim_atual' => null]);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $this->assertStringContainsString('CNPJ: 11.222.333/0001-81', $renderer->html);
        $this->assertStringContainsString('&lt;script&gt;imovel&lt;/script&gt; &amp; rua', $renderer->html);
        $this->assertStringNotContainsString('<script>', $renderer->html);
        $this->assertStringContainsString('Não informado', $renderer->html);
        $this->assertStringContainsString('31/12/2026', $renderer->html);
        $this->assertStringContainsString(str_repeat('Destinação do imóvel. ', 800), $renderer->html);
    }

    public function test_property_lease_type_change_during_rendering_blocks_delivery_without_version_change(): void
    {
        $this->contract->update(['tipo' => 'Locação de Imóvel']);
        $this->renderer(fn () => DB::table('contracts')->where('id', $this->contract->id)->update(['tipo' => 'Fornecimento']));
        $this->get($this->url())->assertStatus(409);
        $this->assertDatabaseCount('activity_events', 0);
    }
}
