<?php

namespace App\Services\Reports;

use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use setasign\Fpdi\PdfParser\StreamReader;

class ReportRenderer
{
    public function write(User $actor, array $filters, string $format, string $path): void
    {
        DB::transaction(fn () => $this->renderSnapshot($actor, $filters, $format, $path));
    }

    private function renderSnapshot(User $actor, array $filters, string $format, string $path): void
    {
        $service = app(ContractReport::class);
        $summary = $service->summary($actor, $filters);
        $metadata = ['Relatório' => ContractReport::TYPES[$filters['report'] ?? 'general'], 'Emitente' => $actor->name,
            'Emitido em' => now()->format('d/m/Y H:i:s').' America/Sao_Paulo', 'Filtros' => json_encode($filters, JSON_UNESCAPED_UNICODE),
            'Registros' => $summary['count'], 'Indicador' => 'Valor inicial; não inclui acumulados ou saldos', 'Total decimal' => $summary['total']];
        if ($format === 'csv') {
            $stream = fopen($path, 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($metadata as $key => $value) {
                fputcsv($stream, [$key, $this->safeCsv((string) $value)], ';', '"', '');
            }
            fputcsv($stream, [], ';', '"', '');
            fputcsv($stream, $service->headers($filters), ';', '"', '');
            foreach ($service->query($actor, $filters)->cursor() as $contract) {
                fputcsv($stream, array_map(fn ($value) => $this->safeCsv((string) $value), $service->row($contract, $actor, $filters)), ';', '"', '');
            }
            if ($summary['groups']) {
                fputcsv($stream, [], ';', '"', '');
                fputcsv($stream, ['Grupo', 'Quantidade', 'Valor inicial decimal'], ';', '"', '');
                foreach ($summary['groups'] as $group) {
                    fputcsv($stream, [$this->safeCsv($group['label']), $group['count'], $group['total']], ';', '"', '');
                }
            }
            fclose($stream);
        } elseif ($format === 'xlsx') {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Contratos');
            $line = 1;
            foreach ($metadata as $key => $value) {
                $sheet->setCellValueExplicit('A'.$line, $key, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('B'.$line++, (string) $value, DataType::TYPE_STRING);
            }
            $line++;
            $header = $line;
            $sheet->fromArray($service->headers($filters), null, 'A'.$line++);
            foreach ($service->query($actor, $filters)->cursor() as $contract) {
                foreach ($service->row($contract, $actor, $filters) as $index => $value) {
                    // Explicit text preserves leading zeros, exact decimals and blocks formulas.
                    $sheet->setCellValueExplicit([$index + 1, $line], (string) $value, DataType::TYPE_STRING);
                }
                $line++;
            }
            $sheet->freezePane('A'.($header + 1));
            $lastColumn = Coordinate::stringFromColumnIndex(count($service->headers($filters)));
            $sheet->setAutoFilter('A'.$header.':'.$lastColumn.max($header, $line - 1));
            foreach (range('A', $lastColumn) as $column) {
                $sheet->getColumnDimension($column)->setWidth(24);
            }
            if ($summary['groups']) {
                $groupSheet = $book->createSheet()->setTitle('Agrupamentos');
                $groupSheet->fromArray(['Grupo', 'Quantidade', 'Valor inicial decimal'], null, 'A1');
                foreach ($summary['groups'] as $index => $group) {
                    foreach (array_values($group) as $column => $value) {
                        $groupSheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
                    }
                }
            }
            (new Xlsx($book))->save($path);
            $book->disconnectWorksheets();
        } else {
            $merged = new PagedPdf;
            $merged->AliasNbPages();
            $rows = [];
            $chunkSummary = $summary;
            $chunkSummary['groups'] = [];
            foreach ($service->query($actor, $filters)->cursor() as $contract) {
                $rows[] = array_map(fn ($value) => is_string($value) ? Str::limit($value, 160) : $value, $service->row($contract, $actor, $filters));
                if (count($rows) === 60) {
                    $this->appendPdf($merged, view('reports.pdf', ['rows' => $rows, 'metadata' => $metadata, 'summary' => $chunkSummary, 'headers' => $service->headers($filters)])->render());
                    $rows = [];
                }
            }
            if ($rows || $summary['count'] === 0) {
                $this->appendPdf($merged, view('reports.pdf', ['rows' => $rows, 'metadata' => $metadata, 'summary' => $chunkSummary, 'headers' => $service->headers($filters)])->render());
            }
            foreach (array_chunk($summary['groups'], 60) as $groups) {
                $this->appendPdf($merged, view('reports.groups-pdf', ['groups' => $groups, 'metadata' => $metadata])->render());
            }
            $merged->Output('F', $path);
        }
    }

    private function appendPdf(PagedPdf $merged, string $html): void
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml($html);
        $pdf->render();
        $pages = $merged->setSourceFile(StreamReader::createByString($pdf->output()));
        for ($page = 1; $page <= $pages; $page++) {
            $template = $merged->importPage($page);
            $size = $merged->getTemplateSize($template);
            $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $merged->useTemplate($template);
        }
        unset($pdf);
        gc_collect_cycles();
    }

    private function safeCsv(string $value): string
    {
        return CsvCell::safe($value);
    }
}
