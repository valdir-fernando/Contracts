<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractAudit;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\AmendmentWriter;
use App\Services\ContractReview;
use App\Services\OverviewMetrics;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRenderer;
use App\Support\ContractFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class InternalEvolutionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private Contract $contract;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'TestingPassword123', 'user_type' => 'Administrador']);
        $this->user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'TestingPassword123']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'evolutions-scope'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $this->user->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => '001/26', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => [],
            'fornecedor_nome_snapshot' => 'Fornecedor', 'fornecedor_documento_snapshot' => '52998224725', 'fundo_nome_snapshot' => 'Fundo',
            'processo' => 'PROC001', 'valor_total' => '1908.6300', 'valor_acumulado' => '2000.0000', 'vl_saldo_disp' => '1000.0000',
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-12-31']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function temp(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'contracts-evolution-');
        $this->files[] = $path;

        return $path;
    }

    public function test_selected_columns_are_consistent_in_ui_csv_xlsx_pdf_and_invalid_columns_are_rejected(): void
    {
        $service = app(ContractReport::class);
        $filters = $service->filters(['columns' => ['initial_amount', 'number', 'document']]);
        $this->assertSame(['Valor inicial (decimal)', 'Contrato', 'Documento'], $service->headers($filters));
        $row = $service->row($this->contract, $this->admin, $filters);
        $this->assertSame(['1908.6300', '001/26', '•••4725'], $row);
        $this->get('/reports?'.http_build_query($filters))->assertOk()->assertSee('Colunas do relatório');
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $path = $this->temp();
            app(ReportRenderer::class)->write($this->admin, $filters, $format, $path);
            if ($format === 'csv') {
                $stream = fopen($path, 'rb');
                $lines = [];
                while (($line = fgetcsv($stream, separator: ';', escape: '')) !== false) {
                    $lines[] = $line;
                }
                fclose($stream);
                $this->assertSame($service->headers($filters), $lines[count($lines) - 2]);
                $this->assertSame(['1908,6300', '001/26', '•••4725'], $lines[count($lines) - 1]);
            } elseif ($format === 'xlsx') {
                $book = IOFactory::load($path);
                $sheet = $book->getActiveSheet();
                $this->assertSame('C', $sheet->getHighestColumn());
                $this->assertSame(['1908,6300', '001/26', '•••4725'], $sheet->rangeToArray('A10:C10')[0]);
                $book->disconnectWorksheets();
            } else {
                $this->assertStringStartsWith('%PDF', file_get_contents($path));
            }
        }
        foreach ([['columns' => ['password']], ['columns' => ['number', 'number']], ['columns' => 'invalid']] as $input) {
            $this->getJson('/reports?'.http_build_query($input))->assertUnprocessable();
        }
    }

    public function test_personal_report_presets_are_private_validate_columns_do_not_save_personal_authorizations_and_respect_current_scope(): void
    {
        $this->actingAs($this->user);
        $this->post('/saved-reports', ['name' => 'Meu relatório', 'filters' => ['report' => 'fund', 'columns' => ['number', 'initial_amount'], 'fund' => $this->contract->fund_id]])->assertRedirect();
        $saved = SavedReport::sole();
        $this->assertSame($this->user->id, $saved->user_id);
        $this->get('/saved-reports/'.$saved->id)->assertRedirect();
        $this->get('/reports')->assertSee('Meu relatório');
        $this->post('/saved-reports', ['name' => 'PII', 'filters' => ['include_personal' => 1, 'reason' => 'Sensitive data request']])->assertSessionHasErrors();
        $this->post('/saved-reports', ['name' => 'Unknown', 'filters' => ['columns' => ['password']]])->assertSessionHasErrors();
        $this->post('/saved-reports', ['name' => 'Meu relatório', 'filters' => ['report' => 'fund']])->assertSessionHasErrors('name');
        $this->actingAs($this->admin);
        $this->get('/saved-reports/'.$saved->id)->assertNotFound();
        $this->delete('/saved-reports/'.$saved->id)->assertNotFound();
        $this->get('/reports')->assertDontSee('Meu relatório');
        $this->actingAs($this->user);
        $this->user->scopes()->detach();
        $response = $this->get('/saved-reports/'.$saved->id);
        $response->assertRedirect();
        $this->get($response->headers->get('Location'))->assertViewHas('summary', fn ($summary) => $summary['count'] === 0);
        $this->delete('/saved-reports/'.$saved->id)->assertRedirect();
        $this->assertDatabaseCount('saved_reports', 0);
        $this->assertDatabaseHas('activity_events', ['action' => 'report_preset_deleted']);
    }

    public function test_internal_api_is_paginated_scoped_masked_read_only_and_audited_for_every_role(): void
    {
        $other = $this->contract->replicate();
        $other->fund_id = null;
        $other->numero = 'OTHER';
        $other->save();
        $this->actingAs($this->user);
        $response = $this->getJson('/internal-api/v1/contracts?per_page=1');
        $response->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.values.initial_amount', '1908.6300')->assertJsonPath('data.0.id', $this->contract->id)->assertDontSee('52998224725');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/internal-api/v1/contracts/'.$other->id)->assertForbidden();
        $this->getJson('/internal-api/v1/contracts/'.$this->contract->id)->assertOk()->assertDontSee('raw_payload')->assertDontSee('52998224725');
        $this->postJson('/internal-api/v1/contracts', [])->assertStatus(405);
        $this->getJson('/internal-api/v1/contracts?per_page=101')->assertUnprocessable();
        $this->actingAs($this->admin);
        $this->getJson('/internal-api/v1/contracts')->assertOk()->assertJsonPath('meta.total', 2)->assertDontSee('52998224725');
        $this->getJson('/internal-api/v1/contracts?include_personal=1')->assertUnprocessable();
        $this->getJson('/internal-api/v1/contracts/'.$this->contract->id.'?include_personal=1')->assertUnprocessable();
        $this->assertDatabaseHas('activity_events', ['action' => 'internal_api_list', 'actor_id' => $this->user->id]);
        $this->actingAs($this->user);
        $this->user->scopes()->detach();
        $this->getJson('/internal-api/v1/contracts')->assertJsonPath('meta.total', 0);
        $this->user->forceFill(['is_active' => false])->save();
        $this->getJson('/internal-api/v1/contracts')->assertUnauthorized();
    }

    public function test_dashboard_metrics_match_visible_contracts_exact_values_and_current_scope(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 11));
        $other = $this->contract->replicate();
        $other->fund_id = null;
        $other->valor_total = '999999.0000';
        $other->save();
        $metrics = app(OverviewMetrics::class)->for($this->user);
        $this->assertSame(1, array_sum(array_column($metrics['validities'], 'count')));
        $this->assertSame('1908.6300', $metrics['summary']['total']);
        $this->assertSame(1, $metrics['months'][2]['count']);
        $this->assertSame(1, $metrics['funds'][0]['count']);
        $this->contract->update(['vigencia_em_revisao' => true]);
        $metrics = app(OverviewMetrics::class)->for($this->user);
        $this->assertSame(0, array_sum(array_column($metrics['months'], 'count')));
        $this->assertSame(1, collect($metrics['validities'])->firstWhere('key', 'em_revisao')['count']);
        $this->actingAs($this->user);
        $this->withSession(['fund_id' => $this->contract->fund_id])->get('/dashboard?year=all')->assertOk()->assertSee('Distribuição e vencimentos')->assertSee('meter', false);
        $this->user->scopes()->detach();
        $this->assertSame(0, app(OverviewMetrics::class)->for($this->user)['summary']['count']);
    }

    private function amendment(): void
    {
        app(AmendmentWriter::class)->save($this->admin, $this->contract, ['fields' => ['N_ADT' => '001', 'Data_ADT' => '2026-10-11', 'Proc_ADT' => 'P001', 'Tipo_ADT' => 'Prorrogação', 'Motivo_ADT' => 'Motivo documental'],
            'status' => 'registrado', 'contract_version' => $this->contract->fresh()->version, 'reason' => 'Aditivo registrado para conferência']);
    }

    private function reviewData(array $extra = []): array
    {
        return array_replace(['version' => $this->contract->fresh()->version, 'confirmation' => '001/26', 'acknowledged' => 1,
            'reason' => 'Conferência sintética dos documentos associados', 'end_date' => '2027-12-31', 'accumulated_amount' => '2.100,1234'], $extra);
    }

    public function test_review_applies_only_explicit_conferred_values_audits_versions_and_reopens_on_amendment_change(): void
    {
        $this->amendment();
        $url = '/contracts/'.$this->contract->id.'/review';
        $this->get($url)->assertOk()->assertSee('Concluir conferência');
        $data = $this->reviewData();
        $this->put($url, $data)->assertRedirect();
        $contract = $this->contract->fresh();
        $this->assertFalse($contract->vigencia_em_revisao);
        $this->assertSame('2100.1234', $contract->valor_acumulado);
        $this->assertSame('1908.6300', $contract->valor_total);
        $this->assertSame('1000.0000', $contract->vl_saldo_disp);
        $this->assertSame('2027-12-31', $contract->end_date->toDateString());
        $this->assertSame('2026-12-31', $contract->vigencia_fim_original->toDateString());
        $this->assertDatabaseHas('contract_audits', ['action' => 'review_confirmed']);
        $this->put($url, $data)->assertConflict()->assertSee('2.100,1234');
        $amendment = ContractAmendment::sole();
        app(AmendmentWriter::class)->save($this->admin, $contract, ['fields' => ['Motivo_ADT' => 'Motivo atualizado'], 'version' => $amendment->version, 'contract_version' => $contract->version, 'reason' => 'Atualização posterior à conferência'], $amendment->id);
        $this->assertTrue($contract->fresh()->vigencia_em_revisao);
        $this->actingAs($this->user);
        $this->get($url)->assertForbidden();
        $this->put($url, $this->reviewData())->assertForbidden();
    }

    public function test_review_rejects_invalid_confirmation_dates_money_and_missing_acknowledgment_and_rolls_back_audit_failure(): void
    {
        $this->amendment();
        $url = '/contracts/'.$this->contract->id.'/review';
        foreach (['confirmation' => 'wrong', 'end_date' => '2025-01-01', 'accumulated_amount' => '1.12345', 'acknowledged' => 0] as $key => $value) {
            $this->put($url, $this->reviewData([$key => $value]))->assertSessionHasErrors($key);
            $this->assertTrue($this->contract->fresh()->vigencia_em_revisao);
        }
        $version = $this->contract->fresh()->version;
        ContractAudit::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            app(ContractReview::class)->confirm($this->admin, $this->contract, $this->reviewData());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
            $this->assertSame($version, $this->contract->fresh()->version);
            $this->assertSame('2000.0000', $this->contract->fresh()->valor_acumulado);
            $this->assertTrue($this->contract->fresh()->vigencia_em_revisao);
        } finally {
            ContractAudit::flushEventListeners();
        }
    }

    private function snapshot(string $number = '019/26'): string
    {
        $fields = array_fill_keys(array_keys(ContractFields::MAP), '');
        $fields = array_replace($fields, ['ID_Contrato' => '1234', 'Contrato' => $number, 'VL_Total' => '1.908,6300']);
        $path = $this->temp();
        file_put_contents($path, 'INSERT INTO `TB_Contratos` (`'.implode('`, `', array_keys($fields)).'`) VALUES ('.implode(',', array_map(fn ($value) => "'".$value."'", $fields)).');');

        return $path;
    }

    public function test_periodic_import_is_disabled_by_default_idempotent_atomic_and_preserves_source_conflicts(): void
    {
        $this->assertFalse(config('legacy.sync_enabled'));
        $path = $this->snapshot();
        $this->artisan('contracts:sync-legacy', ['--file' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('import_runs', 0);
        $this->artisan('contracts:sync-legacy', ['--file' => $path])->assertSuccessful();
        $this->assertDatabaseCount('contracts', 2);
        $count = DB::table('import_runs')->count();
        $this->artisan('contracts:sync-legacy', ['--file' => $path])->expectsOutput('Snapshot já processado; nenhuma alteração.')->assertSuccessful();
        $this->assertDatabaseCount('import_runs', $count);
        $this->artisan('contracts:sync-legacy', ['--file' => $this->snapshot('CHANGED')])->assertSuccessful();
        $this->assertDatabaseHas('contracts', ['Contrato' => '019/26']);
        $this->assertDatabaseMissing('contracts', ['Contrato' => 'CHANGED']);
        $this->assertDatabaseHas('import_issues', ['code' => 'source_changed']);
        $invalid = $this->snapshot('NEW');
        file_put_contents($invalid, "\nINSERT INTO `tb_Aditivos` (`ID`, `Contrato`) VALUES ('invalid', 'NEW');", FILE_APPEND);
        $runs = DB::table('import_runs')->count();
        $this->artisan('contracts:sync-legacy', ['--file' => $invalid])->assertFailed();
        $this->assertDatabaseCount('import_runs', $runs);
        $this->assertDatabaseCount('contracts', 2);
        $this->assertDatabaseHas('activity_events', ['action' => 'legacy_sync_failed']);
        $this->artisan('contracts:sync-legacy', ['--file' => '/nonexistent-snapshot'])->assertFailed();
    }
}
