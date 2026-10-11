<?php

namespace App\Services\Reports;

use App\Models\User;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use setasign\Fpdi\PdfParser\StreamReader;

class AuditReportRenderer
{
    public const HEADERS = ['Módulo', 'Registro interno', 'Quando (America/Sao_Paulo)', 'Ator', 'Ação', 'Motivo'];

    private function query(array $filters)
    {
        $contracts = DB::table('contract_audits')->selectRaw("'Contratos' AS module, contract_id AS target_id, created_at, actor_id, action, reason");
        $users = DB::table('user_audits')->selectRaw("'Usuários' AS module, user_id AS target_id, created_at, actor_id, action, reason");
        $activity = DB::table('activity_events')->selectRaw("'Acesso/exportações' AS module, id AS target_id, created_at, actor_id, action, NULL AS reason");
        $query = DB::query()->fromSub($contracts->unionAll($users)->unionAll($activity), 'events')
            ->leftJoin('users', 'events.actor_id', '=', 'users.id')->select('events.*', 'users.name as actor_name');
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if ($filters[$key] ?? null) {
                $query->whereDate('events.created_at', $operator, $filters[$key]);
            }
        }
        foreach (['actor' => 'actor_id', 'action' => 'action'] as $key => $column) {
            if ($filters[$key] ?? null) {
                $query->where('events.'.$column, $filters[$key]);
            }
        }

        return $query->orderByDesc('events.created_at')->orderBy('events.module')->orderBy('events.target_id');
    }

    public function count(array $filters): int
    {
        return $this->query($filters)->count();
    }

    public function write(User $actor, array $filters, string $format, string $path): int
    {
        abort_unless($actor->isAdmin(), 403);

        return DB::transaction(function () use ($actor, $filters, $format, $path) {
            $count = $this->query($filters)->count();
            $metadata = ['Relatório' => 'Auditoria administrativa', 'Emitente' => $actor->name,
                'Emitido em' => now()->format('d/m/Y H:i:s').' America/Sao_Paulo', 'Filtros' => json_encode($filters, JSON_UNESCAPED_UNICODE),
                'Total de eventos' => $count, 'Indicador' => 'Eventos administrativos; sem totalização financeira'];
            $stream = null;
            $book = null;
            $merged = null;
            $line = 1;
            $rows = [];
            if ($format === 'csv') {
                $stream = fopen($path, 'wb');
                fwrite($stream, "\xEF\xBB\xBF");
                foreach ($metadata as $key => $value) {
                    fputcsv($stream, [$key, CsvCell::safe((string) $value)], ';', '"', '');
                }
                fputcsv($stream, [], ';', '"', '');
                fputcsv($stream, self::HEADERS, ';', '"', '');
            } elseif ($format === 'xlsx') {
                $book = new Spreadsheet;
                $sheet = $book->getActiveSheet();
                $sheet->setTitle('Auditoria');
                foreach ($metadata as $key => $value) {
                    $sheet->setCellValueExplicit('A'.$line, $key, DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit('B'.$line++, (string) $value, DataType::TYPE_STRING);
                }
                $line++;
                $sheet->fromArray(self::HEADERS, null, 'A'.$line++);
            } else {
                $merged = new PagedPdf;
                $merged->AliasNbPages();
            }
            foreach ($this->query($filters)->cursor() as $event) {
                $row = [$event->module, (string) $event->target_id, Carbon::parse($event->created_at)->format('d/m/Y H:i:s'), $event->actor_name ?? 'Conta indisponível', $event->action, $event->reason ?? ''];
                if ($stream) {
                    fputcsv($stream, array_map(fn ($value) => CsvCell::safe($value), $row), ';', '"', '');
                } elseif ($book) {
                    foreach ($row as $column => $value) {
                        $sheet->setCellValueExplicit([$column + 1, $line], $value, DataType::TYPE_STRING);
                    } $line++;
                } else {
                    $rows[] = $row;
                    if (count($rows) === 60) {
                        $this->append($merged, $rows, $metadata);
                        $rows = [];
                    }
                }
            }
            if ($stream) {
                fclose($stream);
            } elseif ($book) {
                $sheet->freezePane('A9');
                foreach (range('A', 'F') as $column) {
                    $sheet->getColumnDimension($column)->setWidth(28);
                } (new Xlsx($book))->save($path);
                $book->disconnectWorksheets();
            } else {
                if ($rows || ! $count) {
                    $this->append($merged, $rows, $metadata);
                } $merged->Output('F', $path);
            }

            return $count;
        });
    }

    private function append(PagedPdf $merged, array $rows, array $metadata): void
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml(view('reports.audit-pdf', compact('rows', 'metadata'))->render());
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
}
