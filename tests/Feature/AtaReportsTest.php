<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaReportPdf;
use App\Infrastructure\Atas\AtaReportQuery;
use App\Infrastructure\Security\LockActor;
use App\Models\AccessScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AtaReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $this->actor = User::create(['name' => 'Pessoa fictícia', 'username' => 'REPORT-ATA', 'password' => 'SyntheticPassword123'])->fresh();
        $this->actingAs($this->actor);
    }

    public function test_report_menu_links_existing_contract_panel_and_new_global_ata_panel(): void
    {
        $response = $this->get('/reports/atas')->assertOk()->assertSee('Relatório Contrato')->assertSee('Relatórios Ata')
            ->assertSee('href="'.route('reports.index').'"', false)->assertSee('href="'.route('reports.atas.index').'"', false)
            ->assertSee('aria-label="Painel Atas"', false)
            ->assertSee('name="report_fund"', false)->assertDontSee('name="report_department"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/<details\s+class="nav-catalogs nav-reports"\s+open\s*>/', $response->getContent());
        $this->get('/reports')->assertOk()->assertSee('Relatórios de contratos')->assertSee('name="report_year"', false);
    }

    public function test_global_rows_ignore_scope_fields_and_private_columns_and_deleted_records(): void
    {
        $funds = [];
        foreach (['A', 'B'] as $name) {
            $funds[] = DB::table('contract_funds')->insertGetId(['name' => 'Fundo '.$name, 'name_key' => 'FUNDO '.$name, 'raw_payload' => '{}']);
        }
        $scope = AccessScope::create(['scope_key' => 'ata-report', 'fund_id' => $funds[0], 'fund' => 'Fundo A']);
        $this->actor->scopes()->attach($scope);
        $first = $this->ata('001/2026', ['FORNECEDOR' => 'Fornecedor Alfa', 'fund_id' => $funds[0], 'CPF_SOCIO' => 'PRIVATE-CPF', 'Doc_ATA' => '%PDF-private']);
        $this->ata('002/2026', ['FORNECEDOR' => 'Fornecedor Beta', 'fund_id' => $funds[1]]);
        $this->ata('003/2026', ['FORNECEDOR' => 'Fornecedor excluído', 'deleted_at' => now()]);
        $this->item($first, '0.1000');
        $this->item($first, '0.2000');
        $this->item($first, '999.0000', ['ADITIVO' => 1]);
        $this->item($first, '888.0000', ['deleted_at' => now()]);
        $response = $this->withSession(['fund_id' => $funds[0]])->get('/reports/atas?report_department=99')
            ->assertOk()->assertSee('Fornecedor Alfa')->assertSee('Fornecedor Beta')->assertSee('R$ 999,30')
            ->assertDontSee('Fornecedor excluído')->assertDontSee('PRIVATE-CPF')->assertDontSee('%PDF-private');
        $this->assertSame(2, $response['count']);
        $this->assertSame('999.3000', $response['total']);
        $this->assertArrayNotHasKey('CPF_SOCIO', $response['rows']->first());
    }

    public function test_year_uses_ata_number_and_combines_with_supplier_process_procurement_and_object(): void
    {
        $this->ata('001/2026', ['DATA_ATA' => '2025-01-01', 'FORNECEDOR' => 'Fornecedor selecionado', 'OBJETO DA ATA' => 'Medicamentos']);
        $this->ata('002/2025', ['FORNECEDOR' => 'Fornecedor anterior', 'OBJETO DA ATA' => 'Medicamentos']);
        $this->ata('003/2026', ['FORNECEDOR' => 'Fornecedor outro', 'OBJETO DA ATA' => 'Veículos']);
        $this->get('/reports/atas?report_year=2026&report_supplier=selecionado&report_process=123&report_bidding=PREG&report_bidding_number=001&report_object=Medicamentos')
            ->assertOk()->assertSee('Fornecedor selecionado')->assertDontSee('Fornecedor anterior')->assertDontSee('Fornecedor outro');
        $this->get('/reports/atas?report_year=2025')->assertOk()->assertSee('Fornecedor anterior')->assertDontSee('Fornecedor selecionado');
        $this->get('/reports/atas?report_year=all')->assertOk()->assertViewHas('count', 3);
    }

    public function test_registered_amount_adds_original_and_all_active_amendment_products_per_ata_in_panel_and_pdf(): void
    {
        $first = $this->ata('001/2026');
        $second = $this->ata('002/2026');
        $previous = $this->ata('003/2025');
        $deleted = $this->ata('004/2026', ['deleted_at' => now()]);
        $amendment = DB::table('tb_ADITIVOS_ATA')->insertGetId(['ID_ATA' => $first, 'ATA' => '001/2026', 'NUMERO' => 2,
            'source_path' => 'amendment-2.pdf', 'source_sha256' => str_repeat('0', 64), 'source_size' => 1,
            'extraction' => 'test', 'link_status' => 'vinculado', 'issues' => '[]'], 'ID');
        $this->item($first, '0.1000');
        $this->item($first, '0.2000', ['ADITIVO' => 1]);
        $this->item($first, '0.3000', ['ADITIVO' => 2, 'ID_ADITIVO' => $amendment]);
        $this->item($first, '777.0000', ['ADITIVO' => 3, 'deleted_at' => now()]);
        $this->item($second, '5.4000', ['ADITIVO' => 1]);
        $this->item($previous, '999.0000', ['ADITIVO' => 1]);
        $this->item($deleted, '888.0000', ['ADITIVO' => 1]);
        $response = $this->get('/reports/atas')->assertOk()->assertSee('R$ 0,60')->assertSee('R$ 5,40')->assertSee('R$ 6,00');
        $rows = collect($response['rows']->items())->keyBy('number');
        $this->assertSame('0.6000', $rows['001/2026']['amount']);
        $this->assertSame('5.4000', $rows['002/2026']['amount']);
        $this->assertSame('6.0000', $response['total']);
        $data = app(AtaReportQuery::class)->for($this->actor, [], false);
        $this->assertSame('6.0000', $data['total']);
        $html = view('reports.atas-pdf', $data)->render();
        foreach (['R$ 0,60', 'R$ 5,40', 'R$ 6,00'] as $amount) {
            $this->assertStringContainsString($amount, $html);
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_header_fund_procurement_and_number_combine_and_are_preserved_in_pagination_totals_and_pdf(): void
    {
        foreach (['Fundo A', 'Fundo B'] as $name) {
            DB::table('contract_funds')->insert(['name' => $name, 'name_key' => mb_strtoupper($name), 'raw_payload' => '{}']);
        }
        for ($i = 1; $i <= 23; $i++) {
            $id = $this->ata(str_pad((string) $i, 3, '0', STR_PAD_LEFT).'/2026', ['FUNDO' => ' Fundo A ', 'FORNECEDOR' => 'Fornecedor filtrado']);
            $this->item($id, '1.0000');
            $this->item($id, '2.0000', ['ADITIVO' => 1]);
        }
        $this->ata('024/2026', ['FUNDO' => 'Fundo B', 'FORNECEDOR' => 'Outro fundo']);
        $this->ata('025/2026', ['FUNDO' => 'Fundo A', 'LICITACAO' => 'CONCORRÊNCIA', 'FORNECEDOR' => 'Outra licitação']);
        $this->ata('026/2026', ['FUNDO' => 'Fundo A', 'N_LICIT' => '002/2026', 'FORNECEDOR' => 'Outro número']);
        $this->ata('027/2025', ['FUNDO' => 'Fundo A', 'FORNECEDOR' => 'Outro ano']);
        $this->ata('028/2026', ['FUNDO' => 'Fundo removido', 'LICITACAO' => 'Modalidade removida', 'deleted_at' => now()]);
        $filters = ['report_year' => 2026, 'report_fund' => 'Fundo A', 'report_bidding' => 'PREGÃO ELETRÔNICO', 'report_bidding_number' => '001/2026'];
        $response = $this->get('/reports/atas?'.http_build_query($filters))->assertOk()->assertSee('Fornecedor filtrado')
            ->assertDontSee('Outro fundo')->assertDontSee('Outra licitação')->assertDontSee('Outro número')->assertDontSee('Outro ano')
            ->assertDontSee('Fundo removido')->assertDontSee('Modalidade removida')->assertSee('R$ 69,00');
        $this->assertSame(23, $response['count']);
        $this->assertSame('69.0000', $response['total']);
        $this->assertCount(20, $response['rows']);
        $this->assertContains('Fundo A', $response['funds']->all());
        $url = $response['rows']->url(2);
        foreach ($filters as $name => $value) {
            $this->assertStringContainsString(http_build_query([$name => $value], '', '&', PHP_QUERY_RFC3986), $url);
        }
        $this->get($url)->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 3)->assertViewHas('total', '69.0000');
        foreach (['report_fund', 'report_bidding', 'report_bidding_number'] as $name) {
            $this->assertSame(1, substr_count($response->getContent(), 'name="'.$name.'"'));
        }
        $data = app(AtaReportQuery::class)->for($this->actor, $filters, false);
        $this->assertCount(23, $data['rows']);
        $this->assertStringContainsString('Fundo: Fundo A', view('reports.atas-pdf', $data)->render());
        $this->get('/reports/atas/pdf?'.http_build_query($filters))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_fund_selector_lists_all_active_registered_funds_even_without_atas_or_actor_scope(): void
    {
        foreach (['Fundo A', 'Fundo B sem atas', 'Fundo C sem escopo'] as $name) {
            DB::table('contract_funds')->insert(['name' => $name, 'name_key' => mb_strtoupper($name), 'raw_payload' => '{}']);
        }
        DB::table('contract_funds')->insert(['name' => 'Fundo excluído', 'name_key' => 'FUNDO EXCLUIDO', 'raw_payload' => '{}', 'deleted_at' => now()]);
        $this->ata('001/2026', ['FUNDO' => 'Fundo A']);
        $response = $this->get('/reports/atas')->assertOk()->assertSee('Fundo A')->assertSee('Fundo B sem atas')->assertSee('Fundo C sem escopo')->assertDontSee('Fundo excluído');
        $this->assertSame(['Fundo A', 'Fundo B sem atas', 'Fundo C sem escopo'], $response['funds']->all());
        $this->get('/reports/atas?'.http_build_query(['report_fund' => 'Fundo B sem atas']))->assertOk()
            ->assertSee('Nenhuma ata encontrada')->assertViewHas('count', 0)->assertViewHas('total', '0.0000');
        $this->get('/reports/atas?'.http_build_query(['report_fund' => 'Fundo A']))->assertOk()->assertViewHas('count', 1);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_supplier_shows_only_latest_amendment_matching_ata_year_and_original_process_in_panel_and_pdf(): void
    {
        $this->ata('001/2026');
        $this->ata('002/2026');
        foreach ([
            ['ATA' => '001/26', 'Proc_ATA' => '123', 'ADT' => 1, 'Data_ADT' => '2026-09-01', 'Proc_ADT' => 'OLD-AMENDMENT'],
            ['ATA' => '001/2026', 'Proc_ATA' => '123', 'ADT' => 2, 'Data_ADT' => '2026-10-01', 'Proc_ADT' => 'LATEST-AMENDMENT', 'Doc_ADT' => '%PDF-private-amendment'],
            ['ATA' => '001/2026', 'Proc_ATA' => 'different', 'ADT' => 3, 'Data_ADT' => '2026-11-01', 'Proc_ADT' => 'WRONG-PROCESS'],
            ['ATA' => '001/2025', 'Proc_ATA' => '123', 'ADT' => 4, 'Data_ADT' => '2026-11-02', 'Proc_ADT' => 'WRONG-YEAR'],
            ['ATA' => '001/2026', 'Proc_ATA' => null, 'ADT' => 5, 'Data_ADT' => '2026-11-03', 'Proc_ADT' => 'MISSING-ORIGINAL-PROCESS'],
        ] as $amendment) {
            DB::table('tb_ADT_ATA')->insert($amendment);
        }
        $response = $this->get('/reports/atas')->assertOk()->assertSee('Aditivo 2')->assertSee('Processo LATEST-AMENDMENT')
            ->assertDontSee('OLD-AMENDMENT')->assertDontSee('WRONG-PROCESS')->assertDontSee('WRONG-YEAR')->assertDontSee('MISSING-ORIGINAL-PROCESS')->assertDontSee('%PDF-private-amendment');
        $rows = collect($response['rows']->items())->keyBy('number');
        $this->assertNull($rows['002/2026']['amendment']);
        $this->assertSame(['number' => '2', 'process' => 'LATEST-AMENDMENT'], $rows['001/2026']['amendment']);
        $data = app(AtaReportQuery::class)->for($this->actor, [], false);
        $html = view('reports.atas-pdf', $data)->render();
        $this->assertStringContainsString('Aditivo 2', $html);
        $this->assertStringContainsString('Processo LATEST-AMENDMENT', $html);
        $this->assertStringNotContainsString('OLD-AMENDMENT', $html);
        $this->assertStringNotContainsString('%PDF-private-amendment', $html);
    }

    public function test_amendment_without_pdf_or_process_is_shown_and_date_ties_use_latest_record(): void
    {
        $this->ata('001/2026');
        DB::table('tb_ADT_ATA')->insert(['ATA' => '001/2026', 'Proc_ATA' => '123', 'ADT' => 1, 'Data_ADT' => '2026-10-01', 'Proc_ADT' => 'EARLIER-RECORD']);
        DB::table('tb_ADT_ATA')->insert(['ATA' => '001/2026', 'Proc_ATA' => '123', 'ADT' => 2, 'Data_ADT' => '2026-10-01', 'Proc_ADT' => null]);
        $this->get('/reports/atas')->assertOk()->assertSee('Aditivo 2')->assertDontSee('EARLIER-RECORD')
            ->assertViewHas('rows', fn ($rows) => $rows->first()['amendment'] === ['number' => '2', 'process' => '']);
    }

    public function test_final_filter_ignores_year_and_current_filter_uses_known_start_and_exclusive_end(): void
    {
        $this->ata('001/2025', ['FORNECEDOR' => 'Vigente selecionado', 'INICIO_ATA' => '2025-01-01', 'FINAL_ATA' => '2026-11-30']);
        $this->ata('002/2026', ['FORNECEDOR' => 'Vence hoje', 'INICIO_ATA' => '2026-01-01', 'FINAL_ATA' => '2026-11-10']);
        $this->ata('003/2026', ['FORNECEDOR' => 'Futuro', 'INICIO_ATA' => '2026-11-20', 'FINAL_ATA' => '2026-12-20']);
        $this->ata('004/2026', ['FORNECEDOR' => 'Indeterminado']);
        $this->get('/reports/atas?report_year=2026&report_current=1&report_end_from=2026-11-01&report_end_to=2026-12-31')
            ->assertOk()->assertSee('Vigente selecionado')->assertDontSee('Vence hoje')->assertDontSee('Futuro')->assertDontSee('Indeterminado')
            ->assertViewHas('count', 1)->assertViewHas('filters', fn ($filters) => $filters['report_year'] === 'all');
        $this->get('/reports/atas?report_end_to=2026-11-10')->assertOk()->assertSee('Vence hoje')->assertDontSee('Vigente selecionado');
    }

    public function test_invalid_dates_and_oversized_filters_are_rejected(): void
    {
        foreach ([['report_end_from' => '2026-12-01', 'report_end_to' => '2026-11-01'], ['report_end_from' => '2026-02-30'], ['report_year' => 2027], ['report_supplier' => str_repeat('x', 256)]] as $filters) {
            $this->getJson('/reports/atas?'.http_build_query($filters))->assertUnprocessable();
        }
    }

    public function test_final_updates_from_amendment_and_drives_current_date_filters_and_pdf_without_writing_ata(): void
    {
        $this->ata('005/2025', ['FORNECEDOR' => 'Ata prorrogada', 'INICIO_ATA' => '2025-06-01', 'FINAL_ATA' => '2026-10-31']);
        $this->get('/reports/atas?report_year=all')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()['ends'] === '2026-10-31');
        DB::table('tb_ADT_ATA')->insert(['ATA' => '005/25', 'Proc_ATA' => '123', 'ADT' => 1, 'Data_ADT' => '2026-10-30', 'FINAL' => '2027-06-01']);
        $this->ata('006/2026', ['FORNECEDOR' => 'Ata com término reduzido', 'INICIO_ATA' => '2026-01-01', 'FINAL_ATA' => '2030-12-31']);
        DB::table('tb_ADT_ATA')->insert(['ATA' => '006/2026', 'Proc_ATA' => '123', 'ADT' => 1, 'Data_ADT' => '2026-10-30', 'FINAL' => '2026-11-09']);
        $filters = ['report_year' => '2026', 'report_current' => 1, 'report_end_from' => '2027-06-01', 'report_end_to' => '2027-06-01'];
        $response = $this->get('/reports/atas?'.http_build_query($filters))->assertOk()->assertSee('Ata prorrogada')->assertSee('01/06/2027')->assertDontSee('Ata com término reduzido');
        $this->assertSame(1, $response['count']);
        $this->assertSame('2027-06-01', $response['rows']->first()['ends']);
        $this->get('/reports/atas?report_current=1&report_year=all')->assertOk()->assertViewHas('count', 1);
        $this->get('/reports/atas?report_end_to=2026-10-31')->assertOk()->assertViewHas('count', 0);
        $data = app(AtaReportQuery::class)->for($this->actor, $filters, false);
        $this->assertSame('2027-06-01', $data['rows']->first()['ends']);
        $this->assertStringContainsString('01/06/2027', view('reports.atas-pdf', $data)->render());
        $this->assertDatabaseHas('tb_ATA', ['ATA' => '005/2025', 'FINAL_ATA' => '2026-10-31']);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_missing_amendment_end_falls_back_to_ata_and_other_years_or_processes_do_not_override(): void
    {
        $this->ata('001/2026', ['FINAL_ATA' => '2026-11-30']);
        $this->ata('002/2026', ['FINAL_ATA' => '2026-12-31']);
        foreach ([
            ['ATA' => '001/2026', 'Proc_ATA' => '123', 'ADT' => 1, 'Data_ADT' => '2026-09-01', 'FINAL' => '2028-01-01'],
            ['ATA' => '001/2026', 'Proc_ATA' => '123', 'ADT' => 2, 'Data_ADT' => '2026-10-01', 'FINAL' => null],
            ['ATA' => '001/2025', 'Proc_ATA' => '123', 'ADT' => 3, 'Data_ADT' => '2026-11-01', 'FINAL' => '2029-01-01'],
            ['ATA' => '001/2026', 'Proc_ATA' => 'other', 'ADT' => 4, 'Data_ADT' => '2026-11-02', 'FINAL' => '2030-01-01'],
        ] as $amendment) {
            DB::table('tb_ADT_ATA')->insert($amendment);
        }
        $response = $this->get('/reports/atas')->assertOk();
        $rows = collect($response['rows']->items())->keyBy('number');
        $this->assertSame('2026-11-30', $rows['001/2026']['ends']);
        $this->assertSame('2026-12-31', $rows['002/2026']['ends']);
    }

    public function test_pdf_rejects_amendment_end_changed_during_rendering(): void
    {
        $this->ata('001/2026', ['FINAL_ATA' => '2026-11-30']);
        DB::table('tb_ADT_ATA')->insert(['ATA' => '001/2026', 'Proc_ATA' => '123', 'ADT' => 1, 'FINAL' => '2027-11-30']);
        $pdf = new class(app(AtaReportQuery::class), app(LockActor::class)) extends AtaReportPdf
        {
            protected function render(array $data): string
            {
                DB::table('tb_ADT_ATA')->update(['FINAL' => '2028-11-30']);

                return '%PDF-rendered';
            }
        };
        try {
            $pdf->generate($this->actor, []);
            $this->fail('Changed amendment validity must invalidate the rendered report.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_pagination_preserves_combined_filters_and_pdf_includes_all_matching_rows(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->ata(str_pad((string) $i, 3, '0', STR_PAD_LEFT).'/2025', ['FORNECEDOR' => 'Fornecedor série']);
        }
        $response = $this->get('/reports/atas?report_year=2025&report_supplier=série')->assertOk();
        if ($directory = getenv('ATAS_REPORT_PREVIEW_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/atas.html', $response->getContent());
        }
        $this->assertCount(20, $response['rows']);
        $this->assertStringContainsString('report_supplier=', $response['rows']->url(2));
        $this->get('/reports/atas?report_year=2025&report_supplier=série&page=2')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 3);
        $data = app(AtaReportQuery::class)->for($this->actor, ['report_year' => 2025, 'report_supplier' => 'série', 'page' => 2], false);
        $this->assertCount(23, $data['rows']);
        $pdf = $this->get('/reports/atas/pdf?report_year=2025&report_supplier=série&page=2')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => 'report_exported']);
        $this->assertSame(23, json_decode(DB::table('activity_events')->value('metadata'), true)['count']);
    }

    public function test_source_html_is_escaped_and_sensitive_fields_are_not_in_pdf_template(): void
    {
        $this->ata('001/2026', ['FORNECEDOR' => '<script>alert(1)</script>', 'OBJETO DA ATA' => '<img src="https://example.com/private">', 'CPF_SOCIO' => 'PRIVATE-CPF']);
        $response = $this->get('/reports/atas')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $data = app(AtaReportQuery::class)->for($this->actor, [], false);
        $html = view('reports.atas-pdf', $data)->render();
        $this->assertStringNotContainsString('PRIVATE-CPF', $html);
        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringNotContainsString('name="report_department"', $response->getContent());
    }

    public function test_guest_and_revoked_sessions_cannot_open_panel_or_pdf(): void
    {
        auth()->logout();
        $this->get('/reports/atas')->assertRedirect('/login');
        $this->get('/reports/atas/pdf')->assertRedirect('/login');
        $this->actingAs($this->actor);
        User::whereKey($this->actor->id)->update(['session_version' => 1]);
        $this->get('/reports/atas/pdf')->assertForbidden();
        $this->actingAs($this->actor->fresh());
        $this->get('/reports/atas/pdf')->assertRedirect('/login');
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_pdf_reauthorizes_actor_after_rendering_before_audit_and_delivery(): void
    {
        $pdf = new class(app(AtaReportQuery::class), app(LockActor::class)) extends AtaReportPdf
        {
            protected function render(array $data): string
            {
                User::query()->update(['is_active' => false]);

                return '%PDF-rendered';
            }
        };
        try {
            $pdf->generate($this->actor, []);
            $this->fail('Revoked actor must not receive PDF.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_pdf_rejects_data_changed_during_rendering(): void
    {
        $this->ata('001/2026');
        $pdf = new class(app(AtaReportQuery::class), app(LockActor::class)) extends AtaReportPdf
        {
            protected function render(array $data): string
            {
                DB::table('tb_ATA')->update(['FORNECEDOR' => 'Alterado durante renderização']);

                return '%PDF-rendered';
            }
        };
        try {
            $pdf->generate($this->actor, []);
            $this->fail('Changed data must invalidate the rendered PDF.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_summary_link_opens_ata_information_funds_and_products_with_private_projection(): void
    {
        $id = $this->ata('001/2026', ['FUNDO' => 'Fundo gestor', 'CPF_SOCIO' => 'PRIVATE-CPF', 'Doc_ATA' => '%PDF-private']);
        $other = $this->ata('002/2026');
        DB::table('tb_FUNDOS_ATA')->insert([
            ['ID_ATA' => $id, 'CNPJ_FUNDO' => '00.000.000/0000-02', 'FUNDO' => 'A participante', 'papel' => 'participante'],
            ['ID_ATA' => $id, 'CNPJ_FUNDO' => '00.000.000/0000-01', 'FUNDO' => 'Fundo gestor', 'papel' => 'gerenciador'],
            ['ID_ATA' => $other, 'CNPJ_FUNDO' => '00.000.000/0000-03', 'FUNDO' => 'Fundo de outra ata', 'papel' => 'gerenciador'],
        ]);
        $this->item($id, '0.1000', ['ITEM' => '10', 'DISCRIMINACAO' => 'Produto dez']);
        $this->item($id, '0.2000', ['ITEM' => '2', 'DISCRIMINACAO' => '<script>Produto dois</script>', 'ADITIVO' => 1]);
        $this->item($id, '999.0000', ['deleted_at' => now(), 'DISCRIMINACAO' => 'Produto excluído']);
        $this->item($other, '888.0000', ['DISCRIMINACAO' => 'Produto de outra ata']);
        DB::table('tb_ADT_ATA')->insert(['ATA' => '001/26', 'Proc_ATA' => '123', 'ADT' => 1, 'FINAL' => '2027-01-01']);
        $this->get('/reports/atas')->assertOk()->assertSee('href="'.route('reports.atas.show', $id).'"', false);
        $response = $this->get(route('reports.atas.show', $id))->assertOk()
            ->assertSee('Resumo da ata 001/2026')->assertSee('Objeto fictício')->assertSee('Fornecedor fictício')
            ->assertSee('01/01/2027')->assertSee('Fundo gerenciador')->assertSee('A participante')
            ->assertSee('Produtos da ata')->assertDontSee('Sem ficha informada')->assertDontSee('Produtos da ata por ficha')
            ->assertSee('Aditivo 1')->assertSee('R$ 0,30')
            ->assertDontSee('PRIVATE-CPF')->assertDontSee('%PDF-private')->assertDontSee('Produto excluído')
            ->assertDontSee('Produto de outra ata')->assertDontSee('Fundo de outra ata')
            ->assertDontSee('<script>Produto dois</script>', false)->assertSee('&lt;script&gt;Produto dois&lt;/script&gt;', false)
            ->assertSeeInOrder(['Fundo gestor', 'A participante'])->assertSeeInOrder(['Produto dois', 'Produto dez']);
        $this->assertSame('0.3000', $response['total']);
        if ($directory = getenv('ATAS_REPORT_PREVIEW_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/summary.html', $response->getContent());
        }
        $this->assertSame(['number', 'date', 'process', 'bidding', 'bidding_number', 'supplier', 'object', 'period', 'starts', 'ends', 'amendment'], array_keys($response['ata']));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_summary_handles_empty_lists_manager_fallback_and_missing_or_deleted_atas(): void
    {
        $id = $this->ata('001/2026');
        $this->get(route('reports.atas.show', $id))->assertOk()->assertSee('Nenhum fundo participante informado')
            ->assertSee('Nenhum produto cadastrado')->assertSee('R$ 0,00');
        DB::table('tb_ATA')->where('ID', $id)->update(['FUNDO' => 'Gestor histórico']);
        $this->get(route('reports.atas.show', $id))->assertOk()->assertSee('Gestor histórico')->assertSee('Fundo gerenciador');
        DB::table('tb_ATA')->where('ID', $id)->update(['deleted_at' => now()]);
        $this->get(route('reports.atas.show', $id))->assertNotFound();
        $this->get('/reports/atas/999999')->assertNotFound();
        $this->get('/reports/atas/invalid')->assertNotFound();
    }

    public function test_summary_excludes_deleted_funds_and_isolates_amendment_year_and_process(): void
    {
        $id = $this->ata('001/2026', ['FINAL_ATA' => '2026-11-30']);
        DB::table('tb_FUNDOS_ATA')->insert(['ID_ATA' => $id, 'CNPJ_FUNDO' => '00.000.000/0000-02',
            'FUNDO' => 'Fundo removido', 'papel' => 'participante', 'deleted_at' => now()]);
        DB::table('tb_ADT_ATA')->insert([
            ['ATA' => '001/2025', 'Proc_ATA' => '123', 'ADT' => 1, 'FINAL' => '2028-01-01'],
            ['ATA' => '001/2026', 'Proc_ATA' => 'other', 'ADT' => 2, 'FINAL' => '2029-01-01'],
        ]);
        $this->get(route('reports.atas.show', $id))->assertOk()->assertSee('30/11/2026')->assertDontSee('Fundo removido');
    }

    public function test_summary_rejects_guests_and_revoked_actor_snapshots(): void
    {
        $id = $this->ata('001/2026');
        auth()->logout();
        $this->get(route('reports.atas.show', $id))->assertRedirect('/login');
        $this->actingAs($this->actor);
        User::whereKey($this->actor->id)->update(['session_version' => 1]);
        $this->get(route('reports.atas.show', $id))->assertForbidden();
    }

    private function ata(string $number, array $attributes = []): int
    {
        return DB::table('tb_ATA')->insertGetId(array_replace(['ATA' => $number, 'DATA_ATA' => '2026-01-01', 'PROCESSO' => '123',
            'LICITACAO' => 'PREGÃO ELETRÔNICO', 'N_LICIT' => '001/2026', 'FORNECEDOR' => 'Fornecedor fictício', 'OBJETO DA ATA' => 'Objeto fictício',
            'CNPJ_FUNDO' => '00.000.000/0000-01', 'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('0', 64)], $attributes), 'ID');
    }

    private function item(int $ataId, string $value, array $attributes = []): void
    {
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace(['ID_ATA' => $ataId, 'ATA' => '001/2026', 'PROCESSO' => '123', 'LICITACAO' => 'PREGÃO', 'N_LICIT' => '001/2026',
            'ITEM' => (string) (DB::table('tb_PRODUTOS_ATA')->count() + 1), 'DISCRIMINACAO' => 'Produto fictício', 'UND' => 'UN',
            'QTD' => '1.0000', 'VL_UNIT' => $value, 'VL_TOTAL' => $value, 'source_row' => '[]'], $attributes));
    }
}
