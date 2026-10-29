<?php

namespace Tests\Feature;

use App\Infrastructure\Dfd\DfdAccess;
use App\Infrastructure\Dfd\DfdPdf;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DfdPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $department;

    private int $dfd;

    private int $item;

    private int $budget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Pessoa exemplo', 'username' => 'DFD-PDF', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Município de Niquelândia', 'name_key' => 'MUNICIPIO DE NIQUELANDIA', 'document' => '00.000.000/0001-00', 'raw_payload' => '{"End_Fundo":"Endereço institucional","private_field":"SEGREDO_FORA_DA_PROJECAO"}']);
        $this->department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria de Compras', 'name_key' => 'SECRETARIA DE COMPRAS', 'raw_payload' => '{}']);
        DB::table('tb_Produtos_Prodata')->insert(['ID_Prodata' => 10, 'Classe' => 'Material', 'Descricao' => 'Papel A4', 'UND' => 'UN', 'Inativo' => 0]);
        $this->dfd = DB::table('tb_DFD')->insertGetId(['N_DFD' => '001/26', 'fund_id' => $this->fund, 'department_id' => $this->department, 'Fundo' => 'Município de Niquelândia',
            'Requisitante' => 'Secretaria de Compras', 'Data_DFD' => '2026-10-29', 'Data_Conclusao' => '2026-12-10', 'Processo_DFD' => '100/2026', 'Categoria' => 'Material',
            'Necessidade' => 'Atender as unidades administrativas', 'Desricao' => 'Aquisição de papel', 'Prioridade' => 'Alta', 'Vigencia' => '12 meses', 'Local' => 'Almoxarifado', 'End_Local' => 'Rua exemplo, 10',
            'Vinculacao' => 'Sem dependência', 'Observacoes' => 'Entrega em horário comercial', 'Responsavel' => 'Pessoa responsável', 'Decreto' => '123/2026', 'Cargo' => 'Diretor', 'email' => 'exemplo@example.test', 'Ficha' => '00012', 'version' => 1], 'ID');
        $this->item = DB::table('tb_Produtos_DFD')->insertGetId(['ID_DFD' => $this->dfd, 'N_DFD' => '001/26', 'ITEM' => 1, 'COD_PROD' => '00010', 'PROD_SIMPLES' => 'Papel A4', 'CARACT' => 'Pacote com 500 folhas', 'PRODUTO' => 'Papel A4 — Pacote com 500 folhas', 'UND' => 'UN', 'QTD' => '2.5000'], 'ID');
        $this->budget = DB::table('tb_Dotacao')->insertGetId(['Fundo' => 'Município de Niquelândia', 'Secretaria' => 'Secretaria de Compras', 'Ficha' => '00012', 'Orgao' => '02', 'Gestao' => '01', 'Unidade' => '0217', 'Funcao' => '04', 'SubFunc' => '122', 'Programa' => '7008', 'Projeto' => '8040', 'Fonte' => '100', 'Codigo' => '319011', 'Dotacao' => '01.0217.04.122.7008.8040.319011(100)00012'], 'ID_Dotacao');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function url(): string
    {
        return '/dfd-compra/'.$this->dfd.'/pdf';
    }

    private function renderer(?Closure $duringRender = null): DfdPdf
    {
        $renderer = new class(app(DfdAccess::class), $duringRender, DB::transactionLevel()) extends DfdPdf
        {
            public string $capturedHtml = '';

            public function __construct(DfdAccess $access, private ?Closure $duringRender, private int $testTransactionLevel)
            {
                parent::__construct($access);
            }

            protected function render(array $snapshot): string
            {
                // RefreshDatabase owns an outer isolation transaction; the emitter must add none here.
                if (DB::transactionLevel() !== $this->testTransactionLevel) {
                    throw new RuntimeException('PDF rendering must run outside the write transaction.');
                }
                $this->capturedHtml = $this->html($snapshot);
                ($this->duringRender ?? static fn () => null)();

                return '%PDF-synthetic';
            }
        };
        $this->app->instance(DfdPdf::class, $renderer);

        return $renderer;
    }

    private function preview(string $name, string $bytes): void
    {
        if ($directory = getenv('DFD_PDF_PREVIEW_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/'.$name.'.pdf', $bytes);
        }
    }

    public function test_icon_is_next_to_process_with_a_private_saved_record_url_and_disabled_for_new(): void
    {
        $this->get('/dfd-compra?classe=Material')->assertOk()->assertSee('Salve a DFD antes de gerar o PDF');
        $this->get('/dfd-compra/'.$this->dfd)->assertOk()->assertSee('dfd-process-control', false)
            ->assertSee('href="'.route('dfd-compra.pdf', $this->dfd).'"', false)->assertSee('aria-label="Gerar PDF da DFD"', false);
    }

    public function test_real_pdf_is_private_audited_and_uses_saved_data_ignoring_client_fields(): void
    {
        $response = $this->get($this->url().'?Categoria=FORGED&Necessidade=FORGED')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', 'inline; filename="dfd-'.$this->dfd.'.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $event = ActivityEvent::where('action', 'dfd_pdf_generated')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame($this->dfd, $event->metadata['dfd_id']);
        $this->assertSame(hash('sha256', $response->getContent()), $event->metadata['sha256']);
        $this->assertSame(64, strlen($event->metadata['template_sha256']));
        $this->preview('dfd-synthetic', $response->getContent());
    }

    public function test_html_matches_model_sections_preserves_values_and_escapes_input(): void
    {
        DB::table('tb_DFD')->where('ID', $this->dfd)->update(['Necessidade' => '<script>texto sem HTML</script>']);
        DB::table('tb_Produtos_DFD')->insert(['ID_DFD' => $this->dfd, 'N_DFD' => '001/26', 'ITEM' => 2, 'PRODUTO' => 'PRODUTO EXCLUIDO', 'deleted_at' => now()]);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        foreach (['DOCUMENTO DE FORMALIZAÇÃO DE DEMANDA - DFD', '001/26', '29/10/2026', '10/12/2026', 'Material', '100/2026',
            'JUSTIFICATIVA', 'DESCRIÇÃO SUCINTA', 'EXPECTATIVA DE CONSUMO', 'INFORMAÇÕES GERAIS', 'DOTAÇÃO ORÇAMENTÁRIA',
            'OBSERVAÇÕES', 'ÁREA REQUISITANTE', 'Assinatura', 'Papel A4', 'Pacote com 500 folhas', '2.5000', '319011', '00012', 'Pessoa responsável', '123/2026', 'exemplo@example.test', '&lt;script&gt;texto sem HTML&lt;/script&gt;'] as $expected) {
            $this->assertStringContainsString($expected, $renderer->capturedHtml);
        }
        foreach (['<script>', 'SEGREDO_FORA_DA_PROJECAO', 'PRODUTO EXCLUIDO'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $renderer->capturedHtml);
        }
    }

    public function test_pdf_denies_other_funds_deleted_documents_and_unauthenticated_requests(): void
    {
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro Fundo', 'name_key' => 'OUTRO FUNDO', 'raw_payload' => '{}']);
        $this->withSession(['fund_id' => $other])->get($this->url())->assertNotFound();
        $this->withSession(['fund_id' => null])->get($this->url())->assertForbidden();
        DB::table('tb_DFD')->where('ID', $this->dfd)->update(['deleted_at' => now()]);
        $this->withSession(['fund_id' => $this->fund])->get($this->url())->assertNotFound();
        auth()->logout();
        $this->get($this->url())->assertRedirect(route('login'));
        $this->assertDatabaseMissing('activity_events', ['action' => 'dfd_pdf_generated']);
    }

    public function test_scoped_user_can_emit_only_their_department_and_reauthorization_detects_revocation(): void
    {
        $user = User::create(['name' => 'Pessoa autorizada', 'username' => 'DFD-PDF-SCOPED', 'password' => 'SyntheticPassword123']);
        $scope = AccessScope::create(['scope_key' => 'dfd-pdf-department', 'fund_id' => $this->fund, 'fund' => 'Município de Niquelândia', 'department_id' => $this->department, 'department' => 'Secretaria de Compras']);
        $user->scopes()->attach($scope);
        $this->renderer();
        $this->actingAs($user)->get($this->url())->assertOk();
        DB::table('tb_DFD')->where('ID', $this->dfd)->update(['department_id' => null]);
        $this->get($this->url())->assertNotFound();
        DB::table('tb_DFD')->where('ID', $this->dfd)->update(['department_id' => $this->department]);
        $this->renderer(fn () => $user->scopes()->detach());
        $this->get($this->url())->assertForbidden();
        $this->assertSame(1, ActivityEvent::where('action', 'dfd_pdf_generated')->count());
    }

    public function test_changes_to_document_products_budget_or_settings_refuse_stale_pdf(): void
    {
        $callbacks = [
            fn () => DB::table('tb_DFD')->where('ID', $this->dfd)->update(['Necessidade' => 'Alterada durante a renderização']),
            fn () => DB::table('tb_Produtos_DFD')->where('ID', $this->item)->update(['QTD' => '99']),
            fn () => DB::table('tb_Dotacao')->where('ID_Dotacao', $this->budget)->update(['Fonte' => '999']),
            fn () => DB::table('document_settings')->where('id', 1)->increment('version'),
        ];
        foreach ($callbacks as $callback) {
            $this->renderer($callback);
            $this->get($this->url())->assertConflict();
        }
        $this->assertDatabaseMissing('activity_events', ['action' => 'dfd_pdf_generated']);
    }

    public function test_deleted_document_inactive_actor_and_expired_rendering_are_rejected(): void
    {
        $this->renderer(fn () => DB::table('tb_DFD')->where('ID', $this->dfd)->update(['deleted_at' => now()]));
        $this->get($this->url())->assertNotFound();
        DB::table('tb_DFD')->where('ID', $this->dfd)->update(['deleted_at' => null]);
        $this->renderer(fn () => DB::table('users')->where('id', $this->actor->id)->update(['is_active' => false]));
        $this->get($this->url())->assertForbidden();
        DB::table('users')->where('id', $this->actor->id)->update(['is_active' => true]);
        $this->renderer(fn () => $this->travel(6)->minutes());
        $this->get($this->url())->assertConflict();
        $this->assertDatabaseMissing('activity_events', ['action' => 'dfd_pdf_generated']);
    }

    public function test_budget_from_other_department_is_not_disclosed_and_ambiguous_budget_is_rejected(): void
    {
        DB::table('tb_Dotacao')->where('ID_Dotacao', $this->budget)->update(['Secretaria' => 'Outra Secretaria', 'Codigo' => 'CODIGO-RESTRITO']);
        $renderer = $this->renderer();
        $this->get($this->url())->assertOk();
        $this->assertStringNotContainsString('CODIGO-RESTRITO', $renderer->capturedHtml);
        DB::table('tb_Dotacao')->where('ID_Dotacao', $this->budget)->update(['Secretaria' => 'Secretaria de Compras']);
        DB::table('tb_Dotacao')->insert(['Fundo' => 'Município de Niquelândia', 'Secretaria' => 'Secretaria de Compras', 'Ficha' => '00012']);
        $this->get($this->url())->assertUnprocessable();
    }

    public function test_audit_failure_prevents_delivery(): void
    {
        $this->renderer();
        ActivityEvent::creating(fn () => throw new RuntimeException('Audit failed'));
        $this->withoutExceptionHandling();
        try {
            $this->get($this->url());
            $this->fail('PDF must not be delivered without its audit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit failed', $error->getMessage());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertDatabaseMissing('activity_events', ['action' => 'dfd_pdf_generated']);
    }

    public function test_long_product_descriptions_and_many_items_render_complete_pdf(): void
    {
        DB::table('tb_DFD')->where('ID', $this->dfd)->update(['Necessidade' => str_repeat('Justificativa extensa. ', 350).'FIM-DA-JUSTIFICATIVA']);
        DB::table('tb_Produtos_DFD')->where('ID', $this->item)->update(['PRODUTO' => str_repeat('Especificação técnica completa. ', 450).'FIM-DO-PRODUTO-LONGO']);
        for ($i = 2; $i <= 45; $i++) {
            DB::table('tb_Produtos_DFD')->insert(['ID_DFD' => $this->dfd, 'N_DFD' => '001/26', 'ITEM' => $i, 'COD_PROD' => (string) $i, 'PRODUTO' => 'Produto sintético número '.$i, 'UND' => 'UN', 'QTD' => '1.0000']);
        }
        $response = $this->get($this->url())->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->preview('dfd-long', $response->getContent());
    }
}
