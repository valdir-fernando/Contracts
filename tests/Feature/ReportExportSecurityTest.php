<?php

namespace Tests\Feature;

use App\Jobs\GenerateReport;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportExportSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $admin = false): User
    {
        return User::create(['name' => 'Usuário', 'username' => $admin ? 'ADMIN' : 'REGULAR', 'password' => 'SenhaFicticia123', 'user_type' => $admin ? 'Administrador' : 'Usuario']);
    }

    private function fund(string $name): int
    {
        return DB::table('contract_funds')->insertGetId(['name' => $name, 'name_key' => $name, 'raw_payload' => '{}']);
    }

    private function export(User $actor): ReportExport
    {
        return ReportExport::create(['user_id' => $actor->id, 'format' => 'csv', 'filters' => [], 'access_hash' => ReportExport::accessHash($actor), 'expires_at' => now()->addDay()]);
    }

    public function test_hash_uses_bounded_batches_without_hydrating_contracts(): void
    {
        $actor = $this->actor(true);
        foreach (array_chunk(range(1, 1200), 100) as $chunk) {
            DB::table('contracts')->insert(array_map(fn ($id) => ['Contrato' => (string) $id, 'quality_issues' => '[]'], $chunk));
        }
        $hydrated = 0;
        Contract::retrieved(function () use (&$hydrated) {
            $hydrated++;
        });
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'contracts')) {
                $queries[] = $query->sql;
            }
        });
        try {
            $first = ReportExport::accessHash($actor);
            $this->assertCount(3, $queries);
            foreach ($queries as $sql) {
                $this->assertStringContainsString('limit 500', $sql);
            }
            $this->assertSame(0, $hydrated);
            $this->assertSame($first, ReportExport::accessHash($actor));
            DB::table('contracts')->where('id', 1100)->increment('version');
            $this->assertNotSame($first, ReportExport::accessHash($actor));
        } finally {
            Contract::flushEventListeners();
        }
    }

    public function test_hash_respects_departments_unresolved_ownership_and_current_actor(): void
    {
        $actor = $this->actor();
        $fund = $this->fund('A');
        $department = DB::table('contract_departments')->insertGetId(['name' => 'Secretaria', 'name_key' => 'SECRETARIA', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'A'), 'fund' => 'A', 'fund_id' => $fund, 'department' => 'Secretaria', 'department_id' => $department]);
        $actor->scopes()->attach($scope);
        $visible = Contract::create(['numero' => 'VISIBLE', 'fund_id' => $fund, 'department_id' => $department, 'ownership_resolved' => true, 'quality_issues' => []]);
        $hidden = Contract::create(['numero' => 'HIDDEN', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        $hash = ReportExport::accessHash($actor);
        $hidden->increment('version');
        $this->assertSame($hash, ReportExport::accessHash($actor));
        $visible->update(['ownership_resolved' => false]);
        $this->assertNotSame($hash, ReportExport::accessHash($actor));
        $visible->update(['ownership_resolved' => true]);
        $hash = ReportExport::accessHash($actor);
        $scope->update(['department' => null, 'department_id' => null]);
        $this->assertNotSame($hash, ReportExport::accessHash($actor));
        $hash = ReportExport::accessHash($actor);
        User::whereKey($actor->id)->increment('session_version');
        $this->assertNotSame($hash, ReportExport::accessHash($actor));
        $hash = ReportExport::accessHash($actor);
        $actor->delete();
        $this->assertNotSame($hash, ReportExport::accessHash($actor));
    }

    public static function revocations(): array
    {
        return [['session'], ['ownership'], ['scope'], ['expiry'], ['delete_actor'], ['content']];
    }

    #[DataProvider('revocations')]
    public function test_changes_during_generation_revoke_export_and_remove_file(string $change): void
    {
        $actor = $this->actor();
        $fund = $this->fund('A');
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'A'), 'fund' => 'A', 'fund_id' => $fund]);
        $actor->scopes()->attach($scope);
        $contract = Contract::create(['numero' => '001', 'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => []]);
        $export = $this->export($actor);
        $path = null;
        $renderer = $this->mock(ReportRenderer::class);
        $renderer->shouldReceive('write')->once()->andReturnUsing(function ($user, $filters, $format, $file) use ($change, $actor, $contract, $export, &$path) {
            $path = $file;
            file_put_contents($file, 'PRIVATE REPORT');
            match ($change) {
                'session' => User::whereKey($actor->id)->increment('session_version'),
                'ownership' => $contract->update(['ownership_resolved' => false]),
                'scope' => $actor->scopes()->detach(),
                'expiry' => $export->update(['expires_at' => now()->subSecond()]),
                'delete_actor' => $actor->delete(),
                'content' => $contract->increment('version'),
            };
        });
        (new GenerateReport($export->id))->handle($renderer);
        $this->assertSame('revoked', $export->fresh()->status);
        $this->assertNull($export->fresh()->path);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_duplicate_delivery_keeps_ready_file_and_download_rechecks_owner_and_version(): void
    {
        $actor = $this->actor(true);
        $contract = Contract::create(['numero' => '001', 'quality_issues' => []]);
        $export = $this->export($actor);
        $renderer = $this->mock(ReportRenderer::class);
        $renderer->shouldReceive('write')->once()->andReturnUsing(fn ($user, $filters, $format, $file) => file_put_contents($file, 'PRIVATE REPORT'));
        $job = new GenerateReport($export->id);
        $job->handle($renderer);
        $export->refresh();
        $path = storage_path('app/private/'.$export->path);
        try {
            $this->assertSame('ready', $export->status);
            $job->handle($renderer);
            $this->assertSame($export->path, $export->fresh()->path);
            $this->assertDatabaseCount('activity_events', 1);
            $other = $this->actor();
            $this->actingAs($other)->get('/reports/download/'.$export->id)->assertForbidden();
            $this->actingAs($actor)->get('/reports/download/'.$export->id)->assertOk();
            $contract->increment('version');
            $this->get('/reports/download/'.$export->id)->assertForbidden();
        } finally {
            @unlink($path);
        }
    }

    public function test_generation_audit_failure_removes_file_and_never_leaves_a_ready_export(): void
    {
        $actor = $this->actor(true);
        $export = $this->export($actor);
        $path = null;
        $renderer = $this->mock(ReportRenderer::class);
        $renderer->shouldReceive('write')->once()->andReturnUsing(function ($user, $filters, $format, $file) use (&$path) {
            $path = $file;
            file_put_contents($file, 'PRIVATE REPORT');
        });
        ActivityEvent::creating(fn () => throw new \RuntimeException('Sensitive exception with bindings'));
        try {
            (new GenerateReport($export->id))->handle($renderer);
            $this->fail('A falha de auditoria deve impedir a publicação.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Não foi possível gerar o relatório solicitado.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->path);
        $this->assertFileDoesNotExist($path);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public static function detailFailures(): array
    {
        return [['session'], ['audit']];
    }

    #[DataProvider('detailFailures')]
    public function test_detail_pdf_cleans_up_after_revocation_or_audit_failure(string $failure): void
    {
        $actor = $this->actor(true);
        $contract = Contract::create(['numero' => '001', 'quality_issues' => []]);
        $files = glob(storage_path('app/private/detail-*.pdf'));
        if ($failure === 'session') {
            View::composer('reports.detail', function () use ($actor) {
                User::whereKey($actor->id)->increment('session_version');
            });
        } else {
            ActivityEvent::creating(fn () => throw new \RuntimeException('Audit unavailable'));
        }
        try {
            $this->actingAs($actor)->get('/contracts/'.$contract->id.'/pdf')->assertStatus($failure === 'session' ? 403 : 500);
        } finally {
            ActivityEvent::flushEventListeners();
        }
        $this->assertSame($files, glob(storage_path('app/private/detail-*.pdf')));
        $this->assertDatabaseCount('activity_events', 0);
    }
}
