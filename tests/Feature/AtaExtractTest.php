<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaExtractPdf;
use App\Infrastructure\Atas\AtaExtractTemplate;
use App\Infrastructure\Atas\AtaWritePolicy;
use App\Infrastructure\Atas\SaveAtaValidity;
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

class AtaExtractTest extends TestCase
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
        return $this->getJson(route('atas.extract', ['ata' => $this->ata] + array_replace(['version' => 1], $query)));
    }

    private function renderer(): object
    {
        $renderer = new class(app(LockActor::class), app(AtaWritePolicy::class), app(DocumentSettings::class), app(AtaExtractTemplate::class)) extends AtaExtractPdf
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
        $this->app->instance(AtaExtractPdf::class, $renderer);

        return $renderer;
    }

    public function test_validity_modes_save_restore_and_supply_the_exact_extract_period(): void
    {
        $url = route('atas.validity.update', ['ata' => $this->ata]);
        $this->patchJson($url, ['version' => 1, 'one_year_inpc' => true, 'validity_period' => 'FORJADO',
            'start_date' => '2025-06-02', 'end_date' => '2026-06-02'])->assertOk()->assertJsonPath('version', 2);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'VIGENCIA_ANO_INPC' => true, 'PERIODO_ATA' => '1 Ano INPC',
            'INICIO_ATA' => '2025-06-02', 'FINAL_ATA' => '2026-06-02']);
        $this->getJson(route('atas.lookup', ['number' => '2/25']))->assertOk()->assertJsonPath('one_year_inpc', true)
            ->assertJsonPath('start_date', '2025-06-02')->assertJsonPath('validity_period', '1 Ano INPC');
        $renderer = $this->renderer();
        $this->issue(['version' => 2])->assertOk();
        $this->assertSame(2, substr_count($renderer->html, 'CPF: 529.982.247-25'));
        $this->assertStringContainsString('1 (um) ano, contado a partir do primeiro dia útil subsequente à data de divulgação no PNCP e poderá ser prorrogado', $renderer->html);
        $this->assertStringNotContainsString('iniciando-se em', $renderer->html);
        $this->patchJson($url, ['version' => 2, 'one_year_inpc' => false, 'validity_period' => '24 meses',
            'end_date' => '2027-06-02'])->assertOk()->assertJsonPath('version', 3);
        $this->issue(['version' => 3])->assertOk();
        $this->assertStringContainsString('vigência de 24 meses, iniciando-se em 02/06/2025 e término em 02/06/2027 e poderá ser prorrogado', $renderer->html);
        $this->patchJson($url, ['version' => 3])->assertOk()->assertJsonPath('version', 3);
        $this->assertSame(2, ActivityEvent::where('action', 'ata_validity_saved')->count());
        $this->getJson(route('atas.publication', ['ata' => $this->ata, 'version' => 3]))->assertOk();
    }

    public function test_invalid_stale_and_unauthorized_validity_changes_do_not_modify_the_ata(): void
    {
        $url = route('atas.validity.update', ['ata' => $this->ata]);
        $before = (array) DB::table('tb_ATA')->sole();
        $this->patchJson($url, ['version' => 1, 'one_year_inpc' => false, 'start_date' => '2026-01-01', 'end_date' => '2025-01-01'])->assertUnprocessable();
        $this->patchJson($url, ['version' => 2, 'one_year_inpc' => true])->assertConflict();
        $this->assertSame($before, (array) DB::table('tb_ATA')->sole());
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'ATA-VALIDITY-FUND', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $other]);
        $this->actingAs($this->actor->fresh());
        $this->patchJson($url, ['version' => 1, 'one_year_inpc' => true])->assertForbidden();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_validity_audit_failure_rolls_back_all_fields_and_version(): void
    {
        $before = (array) DB::table('tb_ATA')->sole();
        Event::listen('eloquent.creating: '.ActivityEvent::class, fn () => throw new RuntimeException('Audit unavailable'));
        try {
            app(SaveAtaValidity::class)->save($this->actor, $this->ata, ['version' => 1, 'one_year_inpc' => true], $this->fund);
            $this->fail('Audit failure must roll back validity changes.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertSame($before, (array) DB::table('tb_ATA')->sole());
    }

    public function test_extract_uses_saved_ata_manager_and_system_administration_with_private_audited_response(): void
    {
        $renderer = $this->renderer();
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Órgão da sessão', 'name_key' => 'SESSAO', 'raw_payload' => '{}']);
        $this->withSession(['fund_id' => $other]);
        $before = (array) DB::table('tb_ATA')->sole();
        $this->issue(['FUNDO' => 'FORJADO', 'administration' => '1900/1904'])->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('ÓRGÃO GERENCIADOR TESTE', $renderer->html);
        $this->assertStringContainsString('ADM: 2031/2034', $renderer->html);
        $this->assertStringNotContainsString('Gestão 2031–2034', $renderer->html);
        $this->assertStringContainsString('EXTRATO DA ATA DE REGISTRO DE PREÇOS N.º 002/2025', $renderer->html);
        $this->assertStringContainsString('02 de junho de 2025', $renderer->html);
        $this->assertStringContainsString('vigência de 12 meses conforme a ata original., iniciando-se em Não informado e término em Não informado', $renderer->html);
        $this->assertStringContainsString('&lt;Fornecedor fictício&gt;', $renderer->html);
        $this->assertStringNotContainsString('WELLIESE', $renderer->html);
        $this->assertStringNotContainsString('Órgão da sessão', $renderer->html);
        $this->assertSame($before, (array) DB::table('tb_ATA')->sole());
        $event = ActivityEvent::where('action', 'ata_extract_generated')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame('2031/2034', $event->metadata['administration']);
        $this->assertSame($this->ata, $event->metadata['ata_id']);
    }

    public function test_real_pdf_generation_and_both_extract_buttons_render(): void
    {
        $response = $this->issue()->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(5000, strlen($response->getContent()));
        $html = $this->get(route('atas.create'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'data-ata-extract-url='));
        $this->assertSame(2, substr_count($html, 'data-ata-publication-url='));
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
