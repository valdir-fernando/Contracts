<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractFinancialRecord;
use App\Models\ContractHistoryRecord;
use App\Models\ImportRun;
use App\Models\LegacyRecord;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\AmendmentWriter;
use App\Services\ContractReview;
use App\Services\FinancialRecordWriter;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class AuditSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->withoutVite();

        return User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
    }

    public static function mutations(): array
    {
        return [['account.update'], ['saved-reports.destroy'], ['administration.reconcile']];
    }

    #[DataProvider('mutations')]
    public function test_revocation_after_middleware_blocks_mutations_and_audit(string $route): void
    {
        $admin = $this->admin();
        $saved = SavedReport::create(['user_id' => $admin->id, 'name' => 'Relatório original', 'filters' => []]);
        $contract = Contract::create(['numero' => '001/26', 'quality_issues' => []]);
        $run = ImportRun::create(['source_hash' => str_repeat('a', 64), 'status' => 'completed', 'started_at' => now()]);
        $legacy = LegacyRecord::create(['source_table' => 'tb_Aditivos', 'legacy_id' => '1', 'source_hash' => str_repeat('b', 64), 'raw_payload' => [], 'import_run_id' => $run->id]);
        $history = ContractHistoryRecord::create(['kind' => 'amendment', 'legacy_id' => '1', 'legacy_record_id' => $legacy->id, 'match_status' => 'ambiguous', 'candidate_ids' => [], 'quality_issues' => []]);
        Route::getRoutes()->getByName($route)->middleware(RevokeAuditActorAfterMiddleware::class);
        $this->actingAs($admin)->withSession(['auth_version' => 0]);
        if ($route === 'account.update') {
            $this->put('/account', ['name' => 'Nome indevido', 'current_password' => 'SenhaFicticia123'])->assertForbidden();
            $this->assertSame('Admin', $admin->fresh()->name);
        } elseif ($route === 'saved-reports.destroy') {
            $this->delete('/saved-reports/'.$saved->id)->assertForbidden();
            $this->assertNotNull($saved->fresh());
        } else {
            $this->post('/administration/history/'.$history->id.'/reconcile', ['contract_id' => $contract->id, 'reason' => 'Conciliação documentada'])->assertForbidden();
            $this->assertNull($history->fresh()->contract_id);
            $this->assertFalse($contract->fresh()->vigencia_em_revisao);
        }
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertDatabaseCount('contract_audits', 0);
    }

    public static function conflicts(): array
    {
        $cases = [];
        foreach (['amendment', 'finance', 'review'] as $module) {
            foreach ($module === 'review' ? ['revoke', 'delete', 'demote'] : ['revoke', 'delete', 'demote', 'delete_child'] as $change) {
                $cases[] = [$module, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('conflicts')]
    public function test_conflict_forms_reload_actor_and_active_contract(string $module, string $change): void
    {
        $admin = $this->admin();
        $contract = Contract::create(['numero' => '001/26', 'objeto' => 'CONTEUDO RESTRITO', 'quality_issues' => [], 'vigencia_em_revisao' => true]);
        $service = match ($module) {
            'amendment' => AmendmentWriter::class, 'finance' => FinancialRecordWriter::class, 'review' => ContractReview::class,
        };
        $method = $module === 'review' ? 'confirm' : 'save';
        $record = null;
        $url = '/contracts/'.$contract->id.'/review';
        if ($module === 'amendment') {
            $record = ContractAmendment::create(['contract_id' => $contract->id, 'number' => '1', 'event_date' => '2026-10-01', 'payload' => [], 'created_by' => $admin->id, 'updated_by' => $admin->id]);
            $url = '/contracts/'.$contract->id.'/amendments/'.$record->id;
        } elseif ($module === 'finance') {
            $record = ContractFinancialRecord::create(['contract_id' => $contract->id, 'kind' => 'ficha', 'number' => '1', 'year' => 2026, 'event_date' => '2026-10-01', 'payload' => [], 'created_by' => $admin->id, 'updated_by' => $admin->id]);
            $url = '/contracts/'.$contract->id.'/finance/'.$record->id;
        }
        $this->mock($service)->shouldReceive($method)->once()->andReturnUsing(function () use ($change, $admin, $contract, $record) {
            match ($change) {
                'revoke' => User::whereKey($admin->id)->increment('session_version'),
                'delete' => $contract->delete(),
                'delete_child' => $record->delete(),
                'demote' => User::whereKey($admin->id)->update(['user_type' => 'Usuario']),
            };
            throw new ConflictHttpException('Conflito simulado');
        });
        $response = $this->actingAs($admin)->put($url, ['version' => 1, 'reason' => 'Alteração documentada']);
        $response->assertStatus(in_array($change, ['delete', 'delete_child']) ? 404 : 403)->assertDontSee('CONTEUDO RESTRITO');
    }
}

class RevokeAuditActorAfterMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        User::whereKey($request->user()->id)->increment('session_version');

        return $next($request);
    }
}
