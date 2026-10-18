<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\ContractAudit;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use App\Services\Reports\CsvCell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class AuditReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_exports_are_admin_only_filtered_logged_and_exclude_raw_changes(): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura12345', 'user_type' => 'Administrador']);
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SenhaSegura12345']);
        $contract = Contract::create(['numero' => '001/26', 'quality_issues' => []]);
        ContractAudit::create(['actor_id' => $admin->id, 'contract_id' => $contract->id, 'action' => 'updated', 'reason' => 'Correção documentada',
            'changes' => ['cpf_gestor' => ['before' => '52998224725', 'after' => '12345678901']], 'created_at' => '2026-10-18 14:35:22']);
        ActivityEvent::create(['actor_id' => $admin->id, 'action' => 'login', 'metadata' => [], 'created_at' => now()]);
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->actingAs($user)->get('/administration/export/'.$format)->assertForbidden();
            $response = $this->actingAs($admin)->get('/administration/export/'.$format.'?action=updated')->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            $this->assertStringNotContainsString('52998224725', file_get_contents($path));
            if ($format === 'csv') {
                $this->assertStringContainsString('Correção documentada', file_get_contents($path));
                $this->assertStringContainsString('18/10/2026 14:35:22', file_get_contents($path));
                $this->assertStringNotContainsString(';login;', file_get_contents($path));
            } elseif ($format === 'xlsx') {
                $book = IOFactory::load($path);
                $this->assertSame('Cadastro atualizado', $book->getActiveSheet()->getCell('E9')->getValue());
                $this->assertSame('18/10/2026 14:35:22', $book->getActiveSheet()->getCell('C9')->getValue());
                $book->disconnectWorksheets();
            } else {
                $this->assertStringStartsWith('%PDF-', file_get_contents($path));
            }
            unlink($path);
        }
        $this->assertSame(3, ActivityEvent::where('action', 'audit_exported')->count());
        $this->get('/administration')->assertOk()->assertSee('Exportar auditoria PDF');
    }

    public function test_legacy_dates_below_mysql_minimum_remain_unresolved(): void
    {
        $this->assertNull(Normalizer::date('0999-12-31'));
        $this->assertNull(Normalizer::date('0001-01-01'));
        $this->assertSame('1000-01-01', Normalizer::date('1000-01-01'));
        $this->assertSame('-1.2345', CsvCell::safe('-1.2345'));
        $this->assertSame("'=CMD()", CsvCell::safe('=CMD()'));
    }
}
