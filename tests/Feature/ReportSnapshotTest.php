<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use App\Services\Reports\AuditReportRenderer;
use App\Services\Reports\ContractReport;
use App\Services\Reports\ReportRenderer;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public static function formats(): array
    {
        return [['csv'], ['xlsx'], ['pdf']];
    }

    #[DataProvider('formats')]
    public function test_report_dates_are_brazilian_in_every_format_and_storage_remains_iso(string $format): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $contract = Contract::create(['numero' => 'DATAS', 'data_contrato' => '2026-02-01', 'vigencia_inicio' => '2026-02-03',
            'vigencia_fim_original' => '2026-12-30', 'quality_issues' => []]);
        $storedDates = $contract->fresh()->only(['data_contrato', 'vigencia_inicio', 'vigencia_fim_original']);
        $original = $contract->fresh()->getRawOriginal();
        $filters = ['columns' => ['ends_at', 'number', 'signed_at', 'starts_at'], 'reference' => '2026-03-04'];
        $expected = ['30/12/2026', 'DATAS', '01/02/2026', '03/02/2026'];
        $pdfInspected = false;
        if ($format === 'pdf') {
            View::composer('reports.pdf', function ($view) use ($expected, &$pdfInspected) {
                $pdfInspected = true;
                $this->assertSame($expected, $view->getData()['rows'][0]);
                $this->assertStringContainsString('04/03/2026', $view->getData()['metadata']['Filtros']);
                $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}/', $view->getData()['metadata']['Emitido em']);
            });
        }
        $path = tempnam(sys_get_temp_dir(), 'report-dates-');
        try {
            app(ReportRenderer::class)->write($admin, $filters, $format, $path);
            if ($format === 'csv') {
                $content = file_get_contents($path);
                foreach ($expected as $value) {
                    $this->assertStringContainsString($value, $content);
                }
                $this->assertStringContainsString('04/03/2026', $content);
                $this->assertStringNotContainsString('2026-02-01', $content);
                $this->assertStringNotContainsString('2026-12-30', $content);
            } elseif ($format === 'xlsx') {
                $book = IOFactory::load($path);
                $sheet = $book->getActiveSheet();
                $this->assertSame($expected, $sheet->rangeToArray('A10:D10')[0]);
                $this->assertStringContainsString('04/03/2026', $sheet->getCell('B4')->getValue());
                $book->disconnectWorksheets();
            } else {
                $this->assertTrue($pdfInspected);
                $this->assertStringStartsWith('%PDF-', file_get_contents($path));
            }
            $contract->refresh();
            foreach ($storedDates as $field => $date) {
                $this->assertSame($original[$field], $contract->getRawOriginal($field));
                $this->assertSame($date->format('Y-m-d'), $contract->$field->format('Y-m-d'));
            }
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('formats')]
    public function test_rows_and_totals_are_captured_once_and_rendered_after_commit(string $format): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        $contract = Contract::create(['numero' => 'ORIGINAL', 'valor_total' => '10.1001', 'quality_issues' => []]);
        $level = DB::transactionLevel();
        $captured = false;
        Event::listen(TransactionCommitted::class, function () use ($contract, &$captured, $level) {
            if (! $captured && DB::transactionLevel() === $level) {
                $captured = true;
                $contract->update(['numero' => 'ALTERADO', 'valor_total' => '20.0000']);
            }
        });
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'contracts')) {
                $queries++;
            }
        });
        $service = $this->partialMock(ContractReport::class);
        $headers = new ContractReport;
        $service->shouldReceive('headers')->atLeast()->once()->andReturnUsing(function ($filters) use ($level, &$captured, $headers) {
            $this->assertTrue($captured);
            $this->assertSame($level, DB::transactionLevel(), 'A renderização deve ocorrer depois do commit do snapshot.');

            return $headers->headers($filters);
        });
        if ($format === 'pdf') {
            View::composer('reports.pdf', function ($view) use ($level) {
                $this->assertSame($level, DB::transactionLevel());
                $this->assertSame('10.1001', $view->getData()['summary']['total']);
                $this->assertSame('ORIGINAL', $view->getData()['rows'][0][0]);
            });
        }
        $path = tempnam(sys_get_temp_dir(), 'snapshot-');
        try {
            app(ReportRenderer::class)->write($admin, [], $format, $path);
            $this->assertTrue($captured);
            $this->assertSame(1, $queries);
            if ($format === 'csv') {
                $content = file_get_contents($path);
                $this->assertStringContainsString('ORIGINAL', $content);
                $this->assertStringContainsString('10,1001', $content);
                $this->assertStringNotContainsString('ALTERADO', $content);
            } elseif ($format === 'xlsx') {
                $book = IOFactory::load($path);
                $this->assertSame('10,1001', $book->getActiveSheet()->getCell('B7')->getValue());
                $this->assertSame('ORIGINAL', $book->getActiveSheet()->getCell('A10')->getValue());
                $book->disconnectWorksheets();
            }
        } finally {
            unlink($path);
        }
    }

    public function test_audit_pdf_renders_after_snapshot_and_ignores_later_events(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'ADMIN', 'password' => 'SenhaFicticia123', 'user_type' => 'Administrador']);
        ActivityEvent::create(['actor_id' => $admin->id, 'action' => 'login', 'metadata' => [], 'created_at' => now()]);
        $level = DB::transactionLevel();
        View::composer('reports.audit-pdf', function ($view) use ($level, $admin) {
            $this->assertSame($level, DB::transactionLevel());
            ActivityEvent::create(['actor_id' => $admin->id, 'action' => 'logout', 'metadata' => [], 'created_at' => now()]);
            $this->assertCount(1, $view->getData()['rows']);
            $this->assertSame(1, $view->getData()['metadata']['Total de eventos']);
        });
        $path = tempnam(sys_get_temp_dir(), 'audit-snapshot-');
        try {
            $this->assertSame(1, app(AuditReportRenderer::class)->write($admin, [], 'pdf', $path));
            $this->assertDatabaseCount('activity_events', 2);
        } finally {
            unlink($path);
        }
    }
}
