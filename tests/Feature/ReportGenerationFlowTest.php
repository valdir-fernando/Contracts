<?php

namespace Tests\Feature;

use App\Jobs\GenerateReport;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\ReportRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportGenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador']);
    }

    public function test_background_request_queues_even_small_reports_without_rendering_or_count_query(): void
    {
        Bus::fake();
        $actor = $this->admin();
        $this->mock(ReportRenderer::class)->shouldNotReceive('write');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $response = $this->actingAs($actor)->getJson('/reports/export/csv?background=1')->assertStatus(202);
        $export = ReportExport::sole();
        $response->assertExactJson(['id' => $export->id, 'status_url' => route('reports.status', $export)]);
        $this->assertSame('pending', $export->status);
        $this->assertSame('contracts', $export->report_kind);
        Bus::assertDispatched(GenerateReport::class, fn ($job) => $job->exportId === $export->id);
        foreach ($queries as $query) {
            $this->assertFalse(str_contains(strtolower($query), 'count(*)') && str_contains($query, 'contracts'));
        }
    }

    public function test_audit_background_request_queues_and_refuses_regular_actor(): void
    {
        Bus::fake();
        $actor = $this->admin();
        $this->actingAs($actor)->getJson('/administration/export/pdf?background=1')->assertStatus(202);
        $this->assertSame('audit', ReportExport::sole()->report_kind);
        $regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $this->actingAs($regular)->getJson('/administration/export/pdf?background=1')->assertForbidden();
        $this->assertDatabaseCount('report_exports', 1);
    }

    public function test_background_personal_report_cannot_bypass_authorization_or_validation(): void
    {
        Bus::fake();
        $actor = $this->admin();
        $this->actingAs($actor)->getJson('/reports/export/pdf?background=1&include_personal=1')->assertUnprocessable();
        $actor->user_type = 'Usuario';
        $actor->save();
        $this->getJson('/reports/export/pdf?background=1&include_personal=1&reason=Motivo+administrativo')->assertForbidden();
        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_status_is_private_lightweight_and_revalidates_ready_result(): void
    {
        $actor = $this->admin();
        $contract = Contract::create(['numero' => '001/26', 'quality_issues' => []]);
        $export = ReportExport::create(['user_id' => $actor->id, 'format' => 'csv', 'filters' => [],
            'access_hash' => ReportExport::accessHash($actor), 'expires_at' => now()->addDay()]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->actingAs($actor)->getJson('/reports/status/'.$export->id)->assertOk()->assertExactJson([
            'id' => $export->id, 'status' => 'pending', 'format' => 'csv', 'download_url' => null,
        ])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertEmpty(array_filter($queries, fn ($query) => preg_match('/from ["`]?contracts["`]?/i', $query)));
        $export->update(['status' => 'ready', 'path' => 'private.csv', 'sha256' => str_repeat('a', 64), 'size' => 5]);
        $this->getJson('/reports/status/'.$export->id)->assertOk()->assertJsonPath('download_url', route('reports.download', $export))
            ->assertJsonMissingPath('path')->assertJsonMissingPath('filters');
        $contract->increment('version');
        $this->getJson('/reports/status/'.$export->id)->assertForbidden();
        $regular = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $this->actingAs($regular)->getJson('/reports/status/'.$export->id)->assertForbidden();
    }

    public function test_terminal_and_expired_states_do_not_offer_download(): void
    {
        $actor = $this->admin();
        $export = ReportExport::create(['user_id' => $actor->id, 'format' => 'csv', 'filters' => [],
            'access_hash' => ReportExport::accessHash($actor), 'expires_at' => now()->addDay(), 'status' => 'failed']);
        $this->actingAs($actor)->getJson('/reports/status/'.$export->id)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('download_url', null);
        $export->update(['status' => 'processing', 'expires_at' => now()->subSecond()]);
        $this->getJson('/reports/status/'.$export->id)->assertOk()->assertJsonPath('status', 'expired')->assertJsonPath('download_url', null);
    }

    public function test_requested_report_transitions_to_ready_and_delivers_verified_file(): void
    {
        Bus::fake();
        $actor = $this->admin();
        Contract::create(['numero' => 'FLOW-001', 'valor_total' => '10.1001', 'quality_issues' => []]);
        $response = $this->actingAs($actor)->getJson('/reports/export/csv?background=1')->assertStatus(202);
        $export = ReportExport::findOrFail($response->json('id'));
        $this->getJson($response->json('status_url'))->assertJsonPath('status', 'pending');
        (new GenerateReport($export->id))->handle(app(ReportRenderer::class));
        $status = $this->getJson($response->json('status_url'))->assertOk()->assertJsonPath('status', 'ready');
        $download = $this->get($status->json('download_url'))->assertOk();
        $this->assertStringContainsString('FLOW-001', file_get_contents($download->baseResponse->getFile()->getPathname()));
    }

    public function test_snapshot_only_hydrates_the_report_projection_and_preserves_scope(): void
    {
        $actor = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123']);
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'scope', 'fund' => 'Fundo', 'fund_id' => $fund]);
        $actor->scopes()->attach($scope);
        Contract::create(['numero' => 'VISIBLE', 'fund_id' => $fund, 'ownership_resolved' => true, 'objeto' => str_repeat('Large ', 1000), 'cpf_gestor' => '11144477735', 'valor_total' => '10.1001', 'quality_issues' => []]);
        Contract::create(['numero' => 'SECRET', 'valor_total' => '100.0000', 'quality_issues' => []]);
        $hydrated = [];
        Contract::retrieved(function ($model) use (&$hydrated) {
            $hydrated[] = $model->getAttributes();
        });
        try {
            $rows = new ReportRows;
            $summary = app(ContractReport::class)->snapshot($actor, [], $rows, false);
            $this->assertSame(1, $summary['count']);
            $this->assertSame('10.1001', $summary['total']);
            $this->assertCount(1, $hydrated);
            $this->assertArrayNotHasKey('objeto', $hydrated[0]);
            $this->assertArrayNotHasKey('cpf_gestor', $hydrated[0]);
            $this->assertSame('VISIBLE', iterator_to_array($rows)[0][0]);
        } finally {
            Contract::flushEventListeners();
        }
    }
}
