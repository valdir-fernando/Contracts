<?php

namespace Tests\Feature;

use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\ContractLifecycle;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ContractCompletionTest extends TestCase
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
        $fund = DB::table('contract_funds')->insertGetId(['legacy_id' => 1, 'name' => 'Fundo Teste', 'name_key' => 'FUNDO TESTE', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => hash('sha256', 'scope'), 'fund' => 'Fundo Teste', 'fund_id' => $fund]);
        $this->regular->scopes()->attach($scope);
        $this->contract = Contract::create(['numero' => '001/26', 'processo' => 'PROC', 'fund_id' => $fund, 'ownership_resolved' => true,
            'fornecedor_nome_snapshot' => 'Fornecedor', 'fornecedor_documento_snapshot' => '52998224725', 'valor_total' => '1908.6300',
            'vigencia_inicio' => '2026-01-01', 'vigencia_fim_original' => '2026-10-20', 'quality_issues' => []]);
    }

    private function confirmation(): array
    {
        return ['confirmation' => '001/26', 'reason' => 'Correção administrativa', 'version' => $this->contract->fresh()->version];
    }

    public function test_deletion_and_restore_require_admin_confirmation_reason_and_current_version(): void
    {
        $url = '/contracts/'.$this->contract->id;
        $this->actingAs($this->regular)->delete($url, $this->confirmation())->assertForbidden();
        $this->actingAs($this->admin)->delete($url, ['confirmation' => 'wrong'])->assertSessionHasErrors(['confirmation', 'reason', 'version']);
        $this->delete($url, $this->confirmation() + [])->assertRedirect('/contracts');
        $this->assertSoftDeleted($this->contract);
        $this->get($url)->assertNotFound();
        $this->get('/contracts?deleted=1&year=')->assertOk()->assertSee('Restaurar');
        $this->post($url.'/restore', ['version' => 1, 'confirmation' => '001/26', 'reason' => 'Restaurar registro'])->assertStatus(409);
        $this->post($url.'/restore', $this->confirmation())->assertRedirect($url);
        $this->assertNotSoftDeleted($this->contract);
        $this->assertDatabaseHas('contract_audits', ['action' => 'deleted', 'actor_id' => $this->admin->id]);
        $this->assertDatabaseHas('contract_audits', ['action' => 'restored', 'actor_id' => $this->admin->id]);
        $this->assertSame(3, $this->contract->fresh()->version);
    }

    public function test_deletion_audit_failure_rolls_back(): void
    {
        ContractAudit::creating(fn () => throw new \RuntimeException('audit unavailable'));
        try {
            app(ContractLifecycle::class)->change($this->admin, $this->contract, $this->confirmation());
            $this->fail('Expected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('audit unavailable', $error->getMessage());
        } finally {
            ContractAudit::flushEventListeners();
        }
        $this->assertNotSoftDeleted($this->contract);
        $this->assertSame(1, $this->contract->fresh()->version);
    }

    public function test_reports_apply_scope_exact_sums_reference_and_masking(): void
    {
        Contract::create(['numero' => 'SECRET', 'valor_total' => '999999.9999', 'quality_issues' => []]);
        $this->actingAs($this->regular)->get('/reports')->assertOk()->assertSee('1.908,63')->assertDontSee('SECRET')->assertDontSee('52998224725');
        $this->get('/reports?fund=999')->assertOk()->assertSee('0 contratos')->assertDontSee('001/26');
        $this->get('/reports?report=expiring&reference=2026-10-01&days=30')->assertSee('001/26');
        $this->get('/reports?report=expired&reference=2026-10-21')->assertSee('001/26');
        $this->get('/reports?report=expired&reference=2026-10-20')->assertDontSee('001/26');
        $this->contract->update(['vigencia_em_revisao' => true]);
        $this->get('/reports?report=expired&reference=2026-10-21')->assertDontSee('001/26');
        $this->get('/reports?report=quality')->assertSee('001/26');
        $service = app(ContractReport::class);
        $this->assertSame('1908.6300', $service->summary($this->regular, [])['total']);
    }

    public function test_each_export_format_is_valid_and_logged_and_cannot_cross_scope(): void
    {
        $this->actingAs($this->regular);
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $response = $this->get('/reports/export/'.$format)->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $bytes = file_get_contents($path);
            $this->assertStringNotContainsString('52998224725', $bytes);
            if ($format === 'csv') {
                $this->assertStringContainsString('001/26', $bytes);
                $this->assertStringContainsString('1908,6300', $bytes);
            } elseif ($format === 'pdf') {
                $this->assertStringStartsWith('%PDF-', $bytes);
            } else {
                $book = IOFactory::load($path);
                $this->assertStringContainsString('001/26', json_encode($book->getActiveSheet()->toArray(), JSON_UNESCAPED_SLASHES));
                $book->disconnectWorksheets();
            }
            unlink($path);
        }
        $this->assertDatabaseCount('activity_events', 3);
        $this->get('/contracts/'.$this->contract->id.'/pdf')->assertOk();
        $this->get('/administration')->assertForbidden();
        $this->get('/contracts?deleted=1')->assertForbidden();
    }

    public function test_account_requires_current_password_rejects_privilege_fields_and_invalidates_other_sessions(): void
    {
        $this->actingAs($this->regular)->get('/account')->assertOk();
        $data = ['name' => 'Novo nome', 'current_password' => 'incorrect'];
        $this->put('/account', $data)->assertSessionHasErrors('current_password');
        $data['current_password'] = 'SenhaSegura12345';
        $this->put('/account', $data + ['user_type' => 'Administrador'])->assertSessionHasErrors('user_type');
        $this->put('/account', $data + ['password' => 'NovaSenhaSegura6789', 'password_confirmation' => 'NovaSenhaSegura6789'])->assertRedirect('/account');
        $this->assertTrue(Hash::check('NovaSenhaSegura6789', $this->regular->fresh()->password));
        $this->assertSame(1, $this->regular->fresh()->session_version);
        $this->assertFalse($this->regular->fresh()->isAdmin());
        $this->get('/dashboard')->assertOk();
        $this->actingAs($this->admin)->withSession(['auth_version' => 0])->get('/administration')->assertOk()->assertSee('own_password_changed');
    }

    public function test_csv_neutralizes_formulas_and_xlsx_preserves_text(): void
    {
        $this->contract->update(['numero' => '=HYPERLINK("test")']);
        $renderer = app(ReportRenderer::class);
        $path = tempnam(sys_get_temp_dir(), 'report');
        $renderer->write($this->regular, [], 'csv', $path);
        $this->assertStringContainsString("'=HYPERLINK", file_get_contents($path));
        $renderer->write($this->regular, [], 'xlsx', $path);
        $book = IOFactory::load($path);
        $cell = $book->getActiveSheet()->getCell('A10');
        $this->assertSame('=HYPERLINK("test")', $cell->getValue());
        $this->assertSame('s', $cell->getDataType());
        $book->disconnectWorksheets();
        unlink($path);
    }
}
