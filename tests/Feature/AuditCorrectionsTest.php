<?php

namespace Tests\Feature;

use App\Infrastructure\Operations\ProcessHeartbeat;
use App\Jobs\GenerateReport;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\AmendmentWriter;
use App\Services\ContractWriter;
use App\Services\FinancialRecordWriter;
use App\Services\Legacy\HistoryImporter;
use App\Services\Reports\ReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuditCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regular;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'AUDIT_ADMIN', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $this->regular = User::create(['name' => 'Regular', 'username' => 'AUDIT_REGULAR', 'password' => 'TestPassword12345']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->seedContractInspector($fund);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'audit-regression'), 'fund' => 'Fundo', 'fund_id' => $fund]);
        $this->regular->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => 'AUDIT/26', 'processo' => 'PROC', 'fund_id' => $fund, 'ownership_resolved' => true,
            'quality_issues' => [], 'cpf_gestor' => '11144477735', 'fornecedor_documento_snapshot' => '52998224725',
            'fundo_nome_snapshot' => 'Fundo', 'fundo_documento_snapshot' => '11222333000181', 'dados_veiculo' => 'Preservar']);
        $this->contract->refresh();
    }

    public static function emptyValues(): array
    {
        return [[null], [''], [[]]];
    }

    #[DataProvider('emptyValues')]
    public function test_protected_contract_fields_never_enter_patch(mixed $value): void
    {
        $saved = app(ContractWriter::class)->save($this->regular, ['version' => 1, 'reason' => 'Correção sintética',
            'cpf_gestor' => $value, 'fornecedor_documento_snapshot' => $value], $this->contract);
        $this->assertSame('11144477735', $saved->cpf_gestor);
        $this->assertSame('52998224725', $saved->fornecedor_documento_snapshot);
        $this->assertSame('Preservar', $saved->dados_veiculo);
        $changes = ContractAudit::sole()->changes;
        $this->assertArrayNotHasKey('cpf_gestor', $changes);
        $this->assertArrayNotHasKey('fornecedor_documento_snapshot', $changes);
        $saved = app(ContractWriter::class)->save($this->regular, ['version' => 2, 'reason' => 'Limpar campo permitido', 'dados_veiculo' => null], $saved);
        $this->assertNull($saved->dados_veiculo);
    }

    #[DataProvider('emptyValues')]
    public function test_amendment_preserves_protected_and_derived_snapshots(mixed $value): void
    {
        $record = app(AmendmentWriter::class)->save($this->regular, $this->contract, ['contract_version' => 1, 'reason' => 'Cadastro sintético',
            'fields' => ['N_ADT' => '001', 'Data_ADT' => '2026-10-12', 'Proc_ADT' => 'PROC', 'Tipo_ADT' => 'Prorrogação',
                'Motivo_ADT' => 'Motivo sintético', 'CPF_Gestor' => $value, 'Contrato' => $value]]);
        $this->assertSame('11144477735', $record->payload['CPF_Gestor']);
        $this->assertSame('AUDIT/26', $record->payload['Contrato']);
        $record = app(AmendmentWriter::class)->save($this->regular, $this->contract, ['contract_version' => 2, 'version' => 1,
            'reason' => 'Edição sintética', 'fields' => ['CPF_Gestor' => $value, 'Contrato' => $value]], $record->id);
        $this->assertSame('11144477735', $record->payload['CPF_Gestor']);
        $this->assertSame('AUDIT/26', $record->payload['Contrato']);
    }

    #[DataProvider('emptyValues')]
    public function test_financial_record_preserves_readonly_snapshots(mixed $value): void
    {
        $record = app(FinancialRecordWriter::class)->save($this->regular, $this->contract, 'ficha', ['number' => '001', 'year' => 2026,
            'event_date' => '2026-10-12', 'contract_version' => 1, 'reason' => 'Cadastro sintético',
            'fields' => ['Ficha' => '001', 'Valor' => '10,00', 'Fundo' => $value, 'CPF_CNPJ' => $value]]);
        $this->assertSame('Fundo', $record->payload['Fundo']);
        $this->assertSame('52998224725', $record->payload['CPF_CNPJ']);
        $record = app(FinancialRecordWriter::class)->save($this->regular, $this->contract, 'ficha', ['contract_version' => 2, 'version' => 1,
            'reason' => 'Edição sintética', 'fields' => ['Fundo' => $value, 'CPF_CNPJ' => $value]], $record->id);
        $this->assertSame('Fundo', $record->payload['Fundo']);
        $this->assertSame('52998224725', $record->payload['CPF_CNPJ']);
    }

    private function userData(): array
    {
        return ['version' => $this->regular->fresh()->session_version, 'name' => 'Regular', 'username' => 'AUDIT_REGULAR',
            'user_type' => 'Usuario', 'is_active' => true, 'scope_ids' => $this->regular->scopes()->pluck('access_scopes.id')->all()];
    }

    #[DataProvider('emptyValues')]
    public function test_empty_password_is_not_applied_in_user_profile_update(mixed $value): void
    {
        $before = $this->regular->password;
        $this->actingAs($this->admin)->put('/users/'.$this->regular->id, $this->userData() + ['password' => $value])->assertRedirect();
        $this->assertSame($before, $this->regular->fresh()->password);
        $this->assertSame(1, $this->regular->fresh()->session_version);
    }

    public function test_old_user_form_cannot_restore_replaced_scopes(): void
    {
        $second = User::create(['name' => 'Segundo admin', 'username' => 'AUDIT_SECOND', 'password' => 'TestPassword12345', 'user_type' => 'Administrador']);
        $stale = $this->userData();
        $otherScope = AccessScope::create(['scope_key' => hash('sha256', 'audit-other'), 'fund' => 'Outro fundo']);
        $this->actingAs($second)->put('/users/'.$this->regular->id, array_replace($stale, ['scope_ids' => [$otherScope->id]]))->assertRedirect();
        $this->actingAs($this->admin)->put('/users/'.$this->regular->id, array_replace($stale, ['name' => 'Nome de formulário antigo']))->assertStatus(409);
        $this->assertSame([$otherScope->id], $this->regular->fresh()->scopes()->pluck('access_scopes.id')->all());
        $this->assertSame('REGULAR', $this->regular->fresh()->name);
        $this->assertDatabaseCount('user_audits', 1);
    }

    public function test_user_revision_is_required_and_cannot_authorize_regular_actor(): void
    {
        $data = $this->userData();
        unset($data['version']);
        $this->actingAs($this->admin)->putJson('/users/'.$this->regular->id, $data)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->actingAs($this->regular)->putJson('/users/'.$this->regular->id, $this->userData())->assertForbidden();
        $this->assertDatabaseCount('user_audits', 0);
    }

    private function importHistory(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contracts-history-regression-');
        try {
            file_put_contents($path, "INSERT INTO `tb_Aditivos` (`ID`, `Contrato`, `CNPJ_Fornec`, `CNPJ_Fundo`, `Data_ADT`, `Final_Prorrog`, `VL_ADT`, `Motivo_ADT`) VALUES (1,'AUDIT/26','52998224725','11222333000181','2026-10-12','2027-10-01','10,00','Texto sintético');\n");
            app(HistoryImporter::class)->import($path);
        } finally {
            unlink($path);
        }
    }

    public function test_history_import_versions_audits_and_invalidates_export_with_fixed_clock(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->contract->update(['vigencia_em_revisao' => false, 'updated_by' => $this->admin->id]);
        $hash = ReportExport::accessHash($this->admin);
        $this->importHistory();
        $this->assertTrue($this->contract->fresh()->vigencia_em_revisao);
        $this->assertSame(2, $this->contract->fresh()->version);
        $this->assertNull($this->contract->fresh()->updated_by);
        $audit = ContractAudit::sole();
        $this->assertNull($audit->actor_id);
        $this->assertNotNull($audit->import_run_id);
        $this->assertSame('history_imported', $audit->action);
        $this->assertSame(false, $audit->changes['vigencia_em_revisao']['before']);
        $this->assertNotSame($hash, ReportExport::accessHash($this->admin));
        $this->actingAs($this->admin)->put('/contracts/'.$this->contract->id, ['version' => 1, 'reason' => 'Formulário antigo', 'dados_veiculo' => 'Alterado'])->assertStatus(409);
        $this->assertSame('Preservar', $this->contract->fresh()->dados_veiculo);
        $this->importHistory();
        $this->assertSame(2, $this->contract->fresh()->version);
        $this->assertDatabaseCount('contract_audits', 1);
    }

    public function test_import_audit_failure_rolls_back_history_and_contract(): void
    {
        ContractAudit::creating(fn () => throw new RuntimeException('Auditoria indisponível'));
        try {
            $this->importHistory();
            $this->fail('A importação deve reverter junto com a auditoria.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('nenhuma alteração aplicada', $error->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertFalse($this->contract->fresh()->vigencia_em_revisao);
        $this->assertSame(1, $this->contract->fresh()->version);
        $this->assertDatabaseCount('contract_history_records', 0);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    private function export(array $extra = []): ReportExport
    {
        return ReportExport::create(array_replace(['user_id' => $this->admin->id, 'format' => 'csv', 'filters' => [],
            'access_hash' => ReportExport::accessHash($this->admin), 'expires_at' => now()->addDay()], $extra));
    }

    public function test_export_digest_blocks_same_size_substitution_and_old_files_without_digest(): void
    {
        $export = $this->export();
        $renderer = $this->mock(ReportRenderer::class);
        $renderer->shouldReceive('write')->once()->andReturnUsing(fn ($user, $filters, $format, $file) => file_put_contents($file, 'ORIGINAL'));
        (new GenerateReport($export->id))->handle($renderer);
        $export->refresh();
        $path = storage_path('app/private/'.$export->path);
        $this->assertSame(hash('sha256', 'ORIGINAL'), $export->sha256);
        $this->assertSame(8, $export->size);
        $this->actingAs($this->admin)->get('/reports/download/'.$export->id)->assertOk();
        file_put_contents($path, 'ALTERADO');
        $this->get('/reports/download/'.$export->id)->assertStatus(409);
        $this->assertSame(1, ActivityEvent::where('action', 'report_downloaded')->count());
        $export->update(['sha256' => null, 'size' => null]);
        $this->get('/reports/download/'.$export->id)->assertGone();
    }

    public function test_cleanup_expires_pathless_states_and_fails_only_abandoned_processing(): void
    {
        foreach (['pending', 'processing', 'failed', 'revoked'] as $status) {
            $export = $this->export(['status' => $status, 'expires_at' => now()->subSecond()]);
            $this->artisan('contracts:prune-exports')->assertSuccessful();
            $this->assertSame('expired', $export->fresh()->status);
        }
        $old = $this->export(['status' => 'processing', 'processing_token' => 'old', 'processing_started_at' => now()->subSeconds(961)]);
        $legacy = $this->export(['status' => 'processing', 'updated_at' => now()->subSeconds(961)]);
        $active = $this->export(['status' => 'processing', 'processing_token' => 'active', 'processing_started_at' => now()]);
        $this->artisan('contracts:prune-exports')->assertSuccessful();
        $this->assertSame('failed', $old->fresh()->status);
        $this->assertNull($old->fresh()->processing_token);
        $this->assertSame('failed', $legacy->fresh()->status);
        $this->assertSame('processing', $active->fresh()->status);
    }

    public static function lateCompletions(): array
    {
        return [[false], [true]];
    }

    public function test_operations_check_detects_missing_stale_heartbeats_and_waiting_jobs(): void
    {
        $heartbeat = app(ProcessHeartbeat::class);
        $this->artisan('contracts:check-operations')->assertFailed();
        $this->app['events']->dispatch(new Looping('database', 'default'));
        $heartbeat->beat('scheduler');
        $this->artisan('contracts:check-operations')->assertSuccessful();
        $this->travel(151)->seconds();
        $this->artisan('contracts:check-operations', ['--process' => 'scheduler'])->assertFailed();
        $this->artisan('contracts:check-operations', ['--process' => 'worker'])->assertSuccessful();
        $heartbeat->beat('scheduler');
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->subMinutes(6)->timestamp, 'created_at' => now()->subMinutes(6)->timestamp]);
        $this->artisan('contracts:check-operations')->assertFailed();
        $this->travel(900)->seconds();
        $this->artisan('contracts:check-operations', ['--process' => 'worker'])->assertFailed();
    }

    #[DataProvider('lateCompletions')]
    public function test_late_attempt_cannot_publish_or_fail_a_new_ready_export(bool $throw): void
    {
        $export = $this->export();
        $calls = 0;
        $paths = [];
        $renderer = $this->mock(ReportRenderer::class);
        $renderer->shouldReceive('write')->twice()->andReturnUsing(function ($user, $filters, $format, $file) use (&$calls, &$paths, $export, $throw, &$renderer) {
            $calls++;
            $paths[] = $file;
            file_put_contents($file, $calls === 1 ? 'OLD FILE' : 'NEW FILE');
            if ($calls === 1) {
                $export->refresh()->update(['processing_started_at' => now()->subSeconds(961)]);
                $this->artisan('contracts:prune-exports')->assertSuccessful();
                $export->refresh()->update(['status' => 'pending']);
                (new GenerateReport($export->id))->handle($renderer);
                if ($throw) {
                    throw new RuntimeException('Falha tardia');
                }
            }
        });
        $job = new GenerateReport($export->id);
        $queuedPayload = serialize($job);
        try {
            $job->handle($renderer);
            $this->assertFalse($throw);
        } catch (RuntimeException $error) {
            $this->assertTrue($throw);
            $this->assertSame('Não foi possível gerar o relatório solicitado.', $error->getMessage());
        }
        unserialize($queuedPayload)->failed(new RuntimeException('Callback tardio'));
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $this->assertSame(basename($paths[1]), $export->path);
        $this->assertFileDoesNotExist($paths[0]);
        $this->assertSame('NEW FILE', file_get_contents($paths[1]));
        $this->assertSame(1, ActivityEvent::where('action', 'report_generated')->count());
    }

    public function test_failure_callback_from_original_payload_finishes_only_its_claim(): void
    {
        $export = $this->export();
        $job = new GenerateReport($export->id);
        $payload = serialize($job);
        $path = null;
        $renderer = $this->mock(ReportRenderer::class);
        $renderer->shouldReceive('write')->once()->andReturnUsing(function ($user, $filters, $format, $file) use ($payload, &$path) {
            $path = $file;
            file_put_contents($file, 'INTERRUPTED');
            unserialize($payload)->failed(new RuntimeException('Timeout simulado'));
        });
        $job->handle($renderer);
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->processing_token);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('activity_events', 0);
    }
}
