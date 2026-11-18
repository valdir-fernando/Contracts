<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaPublicationPdf;
use App\Infrastructure\Atas\AtaPublicationTemplate;
use App\Infrastructure\Atas\AtaWritePolicy;
use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Security\LockActor;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class AtaPublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Emissão', 'username' => 'ATA-PUBLICATION', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo Gerenciador', 'name_key' => 'GERENCIADOR', 'raw_payload' => '{}']);
        $this->ata = DB::table('tb_ATA')->insertGetId(['ATA' => '002/2025', 'PROCESSO' => 'PROC-TESTE', 'DATA_ATA' => '2025-06-02',
            'LICITACAO' => 'Pregão Eletrônico', 'N_LICIT' => '001/25', 'fund_id' => $this->fund, 'FUNDO' => 'ÓRGÃO GERENCIADOR TESTE',
            'CNPJ_FUNDO' => '04252011000110', 'FORNECEDOR' => '<Fornecedor fictício>', 'CNPJ_FORNECEDOR' => '11222333000181',
            'OBJETO DA ATA' => 'Registro de preços para material fictício.', 'PERIODO_ATA' => '12 meses conforme a ata original.',
            'GESTOR' => 'Gestor fictício', 'CPF_GESTOR' => '52998224725', 'DECRETO_GESTOR' => 'Decreto 1/2025', 'CARGO_GESTOR' => 'Gestor do órgão',
            'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('a', 64), 'version' => 1], 'ID');
        DB::table('document_settings')->where('id', 1)->update(['administration_start_year' => 2031, 'administration_end_year' => 2034]);
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function issue(array $query = [])
    {
        return $this->getJson(route('atas.publication', ['ata' => $this->ata] + array_replace(['version' => 1], $query)));
    }

    private function product(string $item, string $amount, array $extra = []): int
    {
        return DB::table('tb_PRODUTOS_ATA')->insertGetId(array_replace([
            'ID_ATA' => $this->ata, 'ATA' => '002/2025', 'PROCESSO' => 'PROC-TESTE', 'ITEM' => $item,
            'VL_TOTAL' => $amount, 'ADITIVO' => 0,
            'LICITACAO' => 'Pregão', 'N_LICIT' => '001/25', 'DISCRIMINACAO' => 'Produto de teste',
            'UND' => 'UN', 'QTD' => '1.0000', 'VL_UNIT' => $amount, 'source_row' => '{}',
        ], $extra), 'ID');
    }

    public function test_original_active_products_are_summed_exactly_then_rounded_once_with_words(): void
    {
        $this->product('1', '1000.0049');
        $this->product('2', '234.5001');
        $this->product('3', '9000.0000', ['ADITIVO' => 1]);
        $this->product('4', '8000.0000', ['deleted_at' => now()]);
        $other = DB::table('tb_ATA')->insertGetId(['ATA' => '003/2025', 'PROCESSO' => 'OUTRO', 'LICITACAO' => 'Pregão', 'N_LICIT' => '2', 'CNPJ_FUNDO' => '04252011000110', 'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('b', 64)], 'ID');
        $this->product('5', '7000.0000', ['ID_ATA' => $other]);
        $renderer = $this->renderer();
        $before = DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->toJson();
        $this->issue(['amount' => '999999', 'date' => '1900-01-01'])->assertOk();
        $this->assertStringContainsString('VALOR TOTAL</strong>: R$ 1.234,51 (mil, duzentos e trinta e quatro reais e cinquenta e um centavos)', $renderer->html);
        $this->assertStringContainsString('CPF: 529.982.247-25', $renderer->html);
        $this->assertStringNotContainsString('Período:', $renderer->html);
        $this->assertSame($before, DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->toJson());
    }

    public function test_product_changes_during_rendering_invalidate_the_publication(): void
    {
        $id = $this->product('1', '10.0000');
        $renderer = $this->renderer();
        $baseline = DB::transactionLevel();
        foreach (['update', 'delete', 'insert'] as $change) {
            $renderer->during = function () use ($change, $id, $baseline) {
                $this->assertSame($baseline, DB::transactionLevel(), 'PDF rendering must happen outside the write transaction.');
                match ($change) {
                    'update' => DB::table('tb_PRODUTOS_ATA')->where('ID', $id)->update(['VL_TOTAL' => '20.0000']),
                    'delete' => DB::table('tb_PRODUTOS_ATA')->where('ID', $id)->update(['deleted_at' => now()]),
                    'insert' => $this->product('2', '1.0000'),
                };
            };
            $this->issue()->assertConflict()->assertDontSee('%PDF-');
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_negative_product_value_prevents_publication_and_audit(): void
    {
        $this->product('1', '-1.0000');
        $renderer = $this->renderer();
        $this->issue()->assertUnprocessable()->assertDontSee('%PDF-');
        $this->assertSame('', $renderer->html);
        $this->assertDatabaseCount('activity_events', 0);
    }

    private function renderer(): object
    {
        $renderer = new class(app(LockActor::class), app(AtaWritePolicy::class), app(DocumentSettings::class), app(AtaPublicationTemplate::class)) extends AtaPublicationPdf
        {
            public string $html = '';

            public ?Closure $during = null;

            protected function render(string $html): string
            {
                $this->html = $html;
                if ($this->during) {
                    ($this->during)();
                }

                return "%PDF-1.4\nSynthetic extract\n%%EOF\n";
            }
        };
        $this->app->instance(AtaPublicationPdf::class, $renderer);

        return $renderer;
    }

    public function test_publication_uses_saved_ata_manager_and_system_administration_with_private_audited_response(): void
    {
        $renderer = $this->renderer();
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Órgão da sessão', 'name_key' => 'SESSAO', 'raw_payload' => '{}']);
        $this->withSession(['fund_id' => $other]);
        $before = (array) DB::table('tb_ATA')->sole();
        $this->issue(['FUNDO' => 'FORJADO', 'administration' => '1900/1904'])->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('ÓRGÃO GERENCIADOR TESTE', $renderer->html);
        $this->assertStringContainsString('ADM</strong>: 2031/2034', $renderer->html);
        $this->assertStringNotContainsString('<header>', $renderer->html);
        $this->assertStringContainsString('EXTRATO DA ATA 002/2025', $renderer->html);
        $this->assertStringContainsString('DATA</strong>: 02/06/2025', $renderer->html);
        $this->assertStringContainsString('VALOR TOTAL</strong>: Não informado (Não informado)', $renderer->html);
        $this->assertStringContainsString('&lt;Fornecedor fictício&gt;', $renderer->html);
        $this->assertStringNotContainsString('WELLIESE', $renderer->html);
        $this->assertStringNotContainsString('Órgão da sessão', $renderer->html);
        $this->assertSame($before, (array) DB::table('tb_ATA')->sole());
        $event = ActivityEvent::where('action', 'ata_publication_generated')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame('2031/2034', $event->metadata['administration']);
        $this->assertSame($this->ata, $event->metadata['ata_id']);
    }

    public function test_real_pdf_generation_and_both_publication_buttons_render(): void
    {
        $response = $this->issue()->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(5000, strlen($response->getContent()));
        $html = $this->get(route('atas.create'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'data-ata-extract-url='));
        $this->assertSame(2, substr_count($html, 'data-ata-publication-url='));
    }

    public function test_revised_publication_model_highlights_labels_and_places_document_before_supplier_in_one_paragraph(): void
    {
        $renderer = $this->renderer();
        $this->issue()->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$renderer->html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame('EXTRATO DA ATA 002/2025', $xpath->evaluate('string(//p/strong/u[starts-with(., "EXTRATO")])'));
        $this->assertSame('OBJETO', $xpath->evaluate('string(//p/strong/u[.="OBJETO"])'));
        foreach (['ADM', 'Processo/Licitação', 'Fornecedor', 'VALOR TOTAL', 'DATA'] as $label) {
            $this->assertSame(1, $xpath->query('//p/strong[.="'.$label.'"]')->length);
        }
        $supplier = $xpath->query('//p[strong="Fornecedor"]')->item(0);
        $this->assertSame('Fornecedor: 11.222.333/0001-81- <Fornecedor fictício>', $supplier->textContent);
        $this->assertSame('- <Fornecedor fictício>', $xpath->evaluate('string(strong[last()])', $supplier));
        $this->assertSame(1, substr_count($renderer->html, '11.222.333/0001-81'));
    }

    public function test_scoped_issuer_receives_masked_personal_documents(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario', 'catalog_permissions' => '["contract-issuance"]']);
        $scope = AccessScope::create(['scope_key' => 'ATA-PUB-FUND', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh());
        $renderer = $this->renderer();
        $this->issue()->assertOk();
        $this->assertStringNotContainsString('529.982.247-25', $renderer->html);
        $this->assertStringNotContainsString('52998224725', $renderer->html);
        $this->assertStringContainsString('11.222.333/0001-81', $renderer->html);
    }

    public function test_permission_and_fund_scope_are_required_before_rendering(): void
    {
        $renderer = $this->renderer();
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'ATA-PUB-SCOPE', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh());
        $this->issue()->assertForbidden();
        DB::table('users')->where('id', $this->actor->id)->update(['catalog_permissions' => '["contract-issuance"]']);
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $other]);
        $this->actingAs($this->actor->fresh());
        $this->issue()->assertForbidden();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $this->fund]);
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria restrita', 'name_key' => 'RESTRITA', 'raw_payload' => '{}']);
        $scope->update(['department' => 'Secretaria restrita', 'department_id' => $department]);
        $this->issue()->assertForbidden();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => null]);
        $this->actingAs($this->actor->fresh());
        $this->issue()->assertForbidden();
        auth()->logout();
        $this->issue()->assertUnauthorized();
        $this->assertSame('', $renderer->html);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_stale_version_and_deleted_ata_do_not_emit(): void
    {
        $this->renderer();
        $this->issue(['version' => 2])->assertConflict();
        $this->issue(['version' => 0])->assertUnprocessable();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => now()]);
        $this->issue()->assertNotFound();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_changes_during_rendering_reject_output_and_create_no_generation_audit(): void
    {
        $renderer = $this->renderer();
        $renderer->during = fn () => DB::table('tb_ATA')->where('ID', $this->ata)->update(['FUNDO' => 'Alterado']);
        $this->issue()->assertConflict()->assertDontSee('%PDF-');
        $renderer->during = fn () => DB::table('document_settings')->where('id', 1)->update(['administration_end_year' => 2040]);
        $this->issue()->assertConflict()->assertDontSee('%PDF-');
        $renderer->during = fn () => DB::table('users')->where('id', $this->actor->id)->update(['catalog_permissions' => '[]', 'user_type' => 'Usuario']);
        $this->issue()->assertForbidden()->assertDontSee('%PDF-');
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_audit_failure_does_not_deliver_the_generated_pdf(): void
    {
        $renderer = $this->renderer();
        Event::listen('eloquent.creating: '.ActivityEvent::class, fn () => throw new RuntimeException('Audit unavailable'));
        try {
            $renderer->generate($this->actor, $this->ata, 1);
            $this->fail('Audit failure must prevent delivery.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseCount('activity_events', 0);
    }
}
