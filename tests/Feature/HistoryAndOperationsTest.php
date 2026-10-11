<?php

namespace Tests\Feature;

use App\Jobs\GenerateReport;
use App\Livewire\Overview;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractHistoryRecord;
use App\Models\ImportRun;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Legacy\HistoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class HistoryAndOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $regular;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura12345', 'user_type' => 'Administrador']);
        $this->regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SenhaSegura12345']);
        $fund = DB::table('contract_funds')->insertGetId(['legacy_id' => 1, 'name' => 'Fundo Teste', 'name_key' => 'FUNDO TESTE', 'document' => '11222333000181', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'scope'), 'fund' => 'Fundo Teste', 'fund_id' => $fund]);
        $this->regular->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => '001/26', 'processo' => 'PROC', 'fund_id' => $fund, 'ownership_resolved' => true,
            'fundo_nome_snapshot' => 'Fundo Teste', 'fundo_documento_snapshot' => '11222333000181',
            'fornecedor_nome_snapshot' => 'Fornecedor', 'fornecedor_documento_snapshot' => '52998224725', 'quality_issues' => []]);
    }

    private function dump(): string
    {
        return "INSERT INTO `tb_Aditivos` (`ID`, `Contrato`, `CNPJ_Fornec`, `CNPJ_Fundo`, `Data_ADT`, `Final_Prorrog`, `VL_ADT`, `Motivo_ADT`) VALUES (1,'001/26','52998224725','11222333000181','0000-00-00','2027-10-01','1.908,6300','Texto preservado');\n"
            ."INSERT INTO `tb_Produtos_Contratos` (`ID`, `Contrato`, `CNPJ`, `FUNDO`, `Processo`, `Descricao`, `TOTAL_UNIT`) VALUES (1,'001/26','52998224725','Fundo Teste','PROC','Item teste','100,50');\n";
    }

    private function import(string $sql, bool $dryRun = false): array
    {
        $path = tempnam(sys_get_temp_dir(), 'history');
        try {
            file_put_contents($path, $sql);

            return app(HistoryImporter::class)->import($path, $dryRun);
        } finally {
            unlink($path);
        }
    }

    public function test_history_preserves_raw_normalizes_and_imports_idempotently_including_deleted_contracts(): void
    {
        $counts = $this->import($this->dump());
        $this->assertSame(2, $counts['imported']);
        $run = ImportRun::latest('id')->firstOrFail();
        $this->assertSame(DB::table('import_issues')->where('import_run_id', $run->id)->count(), $run->issues_count);
        $entry = ContractHistoryRecord::where('kind', 'amendment')->firstOrFail();
        $this->assertSame($this->contract->id, $entry->contract_id);
        $this->assertSame('1908.6300', $entry->amount);
        $this->assertNull($entry->event_date);
        $this->assertSame('0000-00-00', $entry->source->raw_payload['Data_ADT']);
        $this->assertTrue($this->contract->fresh()->vigencia_em_revisao);
        $this->contract->delete();
        $this->assertSame(2, $this->import($this->dump())['skipped']);
        $this->assertDatabaseCount('contract_history_records', 2);
        $changed = str_replace('Texto preservado', 'Origem alterada', $this->dump());
        $this->assertSame(1, $this->import($changed)['conflict']);
        $this->assertSame('Texto preservado', $entry->fresh()->description);
    }

    public function test_ambiguous_history_requires_admin_review_and_never_changes_money_or_end_dates(): void
    {
        $copy = $this->contract->replicate();
        $copy->save();
        $this->import($this->dump());
        $entry = ContractHistoryRecord::where('kind', 'amendment')->firstOrFail();
        $this->assertNull($entry->contract_id);
        $this->assertSame('ambiguous', $entry->match_status);
        $this->assertCount(2, $entry->candidate_ids);
        $url = '/administration/history/'.$entry->id.'/reconcile';
        $data = ['contract_id' => $this->contract->id, 'reason' => 'Conferido com o documento original'];
        $this->actingAs($this->regular)->post($url, $data)->assertForbidden();
        $this->actingAs($this->admin)->post($url, $data)->assertRedirect('/administration');
        $this->assertSame('manual_confirmed', $entry->fresh()->match_status);
        $this->assertNull($this->contract->fresh()->valor_total);
        $this->assertNull($this->contract->fresh()->vigencia_fim_atual);
        $this->post($url, $data)->assertStatus(409);
        $this->get('/contracts/'.$this->contract->id)->assertSee('Texto preservado');
    }

    public function test_dry_run_and_truncated_history_leave_no_partial_data(): void
    {
        $this->assertSame(2, $this->import($this->dump(), true)['imported']);
        $this->assertDatabaseCount('contract_history_records', 0);
        $this->assertDatabaseCount('import_runs', 0);
        try {
            $this->import($this->dump()."INSERT INTO `tb_Aditivos` (`ID`) VALUES ('broken");
            $this->fail('Expected failure');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('nenhuma alteração', $error->getMessage());
        }
        $this->assertDatabaseCount('contract_history_records', 0);
        $this->assertDatabaseHas('import_runs', ['status' => 'failed']);
    }

    public function test_livewire_search_enforces_current_scope_and_active_account(): void
    {
        Contract::create(['numero' => 'SECRET', 'quality_issues' => []]);
        Livewire::actingAs($this->regular)->test(Overview::class)->assertSee('001/26')->assertDontSee('SECRET')
            ->set('search', 'SECRET')->assertDontSee('001/26')->assertSee('Nenhum contrato encontrado');
        $this->regular->scopes()->detach();
        Livewire::actingAs($this->regular)->test(Overview::class)->assertDontSee('001/26');
        $this->regular->is_active = false;
        $this->regular->save();
        Livewire::actingAs($this->regular)->test(Overview::class)->assertForbidden();
    }

    public function test_native_catalogs_and_scopes_allow_installation_without_private_dump(): void
    {
        $this->actingAs($this->regular)->post('/catalogs', ['kind' => 'fund', 'name' => 'Outro'])->assertForbidden();
        $this->actingAs($this->admin)->get('/catalogs')->assertOk();
        $this->post('/catalogs', ['kind' => 'fund', 'name' => 'Fundo Nativo', 'document' => '11222333000181'])->assertRedirect('/catalogs');
        $fund = DB::table('contract_funds')->where('name', 'Fundo Nativo')->first();
        $this->assertNull($fund->legacy_id);
        $this->post('/catalogs', ['kind' => 'scope', 'fund_id' => $fund->id])->assertRedirect();
        $this->assertDatabaseHas('access_scopes', ['fund_id' => $fund->id, 'department_id' => null]);
        $this->post('/catalogs', ['kind' => 'scope', 'fund_id' => $fund->id])->assertRedirect();
        $this->assertSame(1, AccessScope::where('fund_id', $fund->id)->count());
    }

    public function test_queued_export_is_private_revoked_on_scope_change_and_pruned_after_expiry(): void
    {
        $export = ReportExport::create(['user_id' => $this->regular->id, 'format' => 'csv', 'filters' => [],
            'access_hash' => ReportExport::accessHash($this->regular), 'expires_at' => now()->addDay()]);
        app()->call([new GenerateReport($export->id), 'handle']);
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $path = storage_path('app/private/'.$export->path);
        $this->assertFileExists($path);
        $this->actingAs($this->admin)->get('/reports/download/'.$export->id)->assertForbidden();
        $this->actingAs($this->regular)->get('/reports/download/'.$export->id)->assertOk();
        $this->regular->scopes()->detach();
        $this->get('/reports/download/'.$export->id)->assertForbidden();
        $export->update(['expires_at' => now()->subSecond()]);
        $this->artisan('contracts:prune-exports')->assertSuccessful();
        $this->assertFileDoesNotExist($path);
        $this->assertSame('expired', $export->fresh()->status);
    }

    public function test_admin_provisioning_uses_hidden_input_and_validates_confirmation(): void
    {
        $this->artisan('contracts:create-admin INITIAL')->expectsQuestion('Senha (mínimo 12 caracteres, letras e números)', 'SenhaInicial12345')
            ->expectsQuestion('Confirme a senha', 'SenhaInicial12345')->assertSuccessful();
        $user = User::where('username', 'INITIAL')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('SenhaInicial12345', $user->password));
    }
}
