<?php

namespace Tests\Feature;

use App\Jobs\GenerateReport;
use App\Models\AccessScope;
use App\Models\Contract;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportUpdatedExportsTest extends TestCase
{
    use RefreshDatabase;

    public static function formats(): array
    {
        return [['pdf'], ['xlsx'], ['csv']];
    }

    #[DataProvider('formats')]
    public function test_queued_exports_deliver_updated_values_and_dates_without_review_exclusions(string $format): void
    {
        Bus::fake();
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay());
        $actor = User::create(['name' => 'Regular', 'username' => 'REGULAR', 'password' => 'SyntheticPassword123'])->fresh();
        $fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo teste', 'name_key' => 'FUNDO TESTE', 'raw_payload' => '{}']);
        $scope = AccessScope::create(['scope_key' => 'updated-export', 'fund_id' => $fund, 'fund' => 'Fundo teste']);
        $actor->scopes()->attach($scope);
        $create = fn (array $values) => Contract::create($values + [
            'fund_id' => $fund, 'ownership_resolved' => true, 'quality_issues' => [],
            'vigencia_inicio' => '2026-01-02', 'vigencia_fim_original' => '2030-12-31',
            'vigencia_fim_atual' => '2026-11-30', 'vigencia_em_revisao' => true,
            'valor_total' => '9000.0000', 'valor_acumulado' => '1234.5678',
        ]);
        $contract = $create(['numero' => 'UPDATED']);
        $before = $contract->fresh()->getRawOriginal();
        $create(['numero' => 'ZERO', 'valor_acumulado' => '0.0000']);
        $create(['numero' => 'ORIGINAL-ONLY', 'vigencia_fim_atual' => null]);
        $create(['numero' => 'SECRET', 'fund_id' => null]);
        $expected = [
            ['UPDATED', '02/01/2026', '30/11/2026', '1234,5678', 'Vigente'],
            ['ZERO', '02/01/2026', '30/11/2026', '0,0000', 'Vigente'],
        ];
        $pdfInspected = false;
        if ($format === 'pdf') {
            View::composer('reports.pdf', function ($view) use (&$pdfInspected, $expected) {
                $pdfInspected = true;
                $expected[0][3] = '1.234,5678';
                $this->assertSame($expected, $view->getData()['rows']);
                $this->assertSame('1234.5678', $view->getData()['summary']['total']);
                $this->assertSame('Valor atualizado do contrato', $view->getData()['metadata']['Indicador']);
            });
        }

        $filters = ['background' => 1, 'validity' => 'vigente', 'columns' => ['number', 'starts_at', 'ends_at', 'initial_amount', 'validity']];
        $request = $this->actingAs($actor)->withSession(['fund_id' => $fund])
            ->getJson('/reports/export/'.$format.'?'.http_build_query($filters))->assertStatus(202);
        $export = ReportExport::findOrFail($request->json('id'));
        Bus::assertDispatched(GenerateReport::class, fn ($job) => $job->exportId === $export->id);
        $this->getJson($request->json('status_url'))->assertOk()->assertJsonPath('status', 'pending');
        (new GenerateReport($export->id))->handle(app(ReportRenderer::class));
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $status = $this->getJson($request->json('status_url'))->assertOk()->assertJsonPath('status', 'ready');
        $download = $this->get($status->json('download_url'))->assertOk();
        $path = $download->baseResponse->getFile()->getPathname();
        $this->assertSame($export->sha256, hash_file('sha256', $path));
        $this->assertSame($export->size, filesize($path));
        if ($format === 'pdf') {
            $this->assertTrue($pdfInspected);
            $this->assertStringStartsWith('%PDF-', file_get_contents($path));
        } elseif ($format === 'xlsx') {
            $book = IOFactory::load($path);
            try {
                $this->assertSame($expected, $book->getActiveSheet()->rangeToArray('A10:E11'));
                $this->assertSame('1234,5678', $book->getActiveSheet()->getCell('B7')->getValue());
                $this->assertSame(11, $book->getActiveSheet()->getHighestRow());
            } finally {
                $book->disconnectWorksheets();
            }
        } else {
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
            $this->assertSame($expected, $rows);
            $this->assertStringContainsString('1234,5678', file_get_contents($path));
        }
        $this->assertSame($before, $contract->fresh()->getRawOriginal());
        $this->assertDatabaseHas('activity_events', ['actor_id' => $actor->id, 'action' => 'report_generated']);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $actor->id, 'action' => 'report_downloaded']);
    }
}
