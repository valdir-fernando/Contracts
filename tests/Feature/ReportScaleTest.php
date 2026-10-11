<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRenderer;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReportScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_totals_and_rows_for_zero_one_one_hundred_and_legacy_scale(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura12345', 'user_type' => 'Administrador']);
        $report = app(ContractReport::class);
        $renderer = app(ReportRenderer::class);
        $path = tempnam(sys_get_temp_dir(), 'report-scale');
        try {
            foreach ([0, 1, 100, 2145] as $count) {
                DB::table('contracts')->delete();
                foreach (array_chunk(range(1, max(1, $count)), 100) as $chunk) {
                    if ($count === 0) {
                        break;
                    }
                    DB::table('contracts')->insert(array_map(fn ($index) => ['numero' => str_pad((string) $index, 5, '0', STR_PAD_LEFT).'/26',
                        'valor_total' => '0.1001', 'quality_issues' => '[]', 'administrative_status' => 'rascunho'], $chunk));
                }
                $summary = $report->summary($admin, []);
                $this->assertSame($count, $summary['count']);
                $this->assertSame((string) BigDecimal::of('0.1001')->multipliedBy($count), $summary['total']);
                $renderer->write($admin, [], 'csv', $path);
                $stream = fopen($path, 'rb');
                $rows = [];
                $headerFound = false;
                while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
                    if ($headerFound) {
                        $rows[] = $row;
                    } elseif (($row[0] ?? '') === 'Contrato') {
                        $headerFound = true;
                    }
                }
                fclose($stream);
                $this->assertCount($count, $rows);
                $sum = BigDecimal::of('0.0000');
                foreach ($rows as $row) {
                    $sum = $sum->plus($row[8]);
                }
                $this->assertSame($summary['total'], (string) $sum);
                $renderer->write($admin, [], 'xlsx', $path);
                $book = IOFactory::load($path);
                $sheet = $book->getActiveSheet();
                $this->assertSame($summary['total'], $sheet->getCell('B7')->getValue());
                $this->assertSame(9 + $count, $sheet->getHighestRow());
                $book->disconnectWorksheets();
                $renderer->write($admin, [], 'pdf', $path);
                $this->assertStringStartsWith('%PDF-', file_get_contents($path));
            }
            $this->actingAs($admin)->get('/reports/export/csv')->assertRedirect('/reports/exports');
            $this->assertDatabaseHas('report_exports', ['status' => 'pending', 'user_id' => $admin->id]);
        } finally {
            unlink($path);
        }
    }

    public function test_personal_exports_require_admin_reason_and_default_to_masked_documents(): void
    {
        $this->withoutVite();
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaSegura12345', 'user_type' => 'Administrador']);
        Contract::create(['numero' => '001/26', 'supplier_document_key' => '52998224725', 'fornecedor_documento_snapshot' => '52998224725', 'quality_issues' => []]);
        $this->actingAs($admin)->get('/reports?report=supplier')->assertOk()->assertDontSee('52998224725');
        $this->get('/reports?include_personal=1')->assertSessionHasErrors('reason');
        $this->get('/reports?include_personal=1&reason=Conferencia+administrativa')->assertOk()->assertSee('52998224725');
        $user = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SenhaSegura12345']);
        $this->actingAs($user)->get('/reports/export/csv?include_personal=1&reason=Conferencia+administrativa')->assertForbidden();
    }
}
