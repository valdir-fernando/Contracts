<?php

namespace App\Infrastructure\Dfd;

use App\Infrastructure\Security\CatalogAccess;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;

class DfdPdf
{
    public function __construct(private DfdAccess $access) {}

    public function generate(User $expected, ?int $fundId, int $id): string
    {
        $expires = now()->addMinutes(5)->timestamp;
        $snapshot = DB::transaction(fn () => $this->snapshot($expected, $fundId, $id), 3);
        // Layout and rendering deliberately run without holding database locks.
        $bytes = $this->render($snapshot);
        DB::transaction(function () use ($expected, $fundId, $id, $snapshot, $bytes, $expires) {
            $current = $this->snapshot($expected, $fundId, $id);
            abort_unless(now()->timestamp <= $expires && $current === $snapshot, 409, 'Os dados da DFD mudaram ou a emissão expirou. Gere o PDF novamente.');
            ActivityEvent::create(['actor_id' => $expected->id, 'action' => 'dfd_pdf_generated',
                'metadata' => ['dfd_id' => $id, 'version' => $snapshot['fields']['version'],
                    'source_sha256' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                    'template_sha256' => $snapshot['template_hash'], 'sha256' => hash('sha256', $bytes)], 'created_at' => now()]);
        }, 3);

        return $bytes;
    }

    private function snapshot(User $expected, ?int $fundId, int $id): array
    {
        [$actor, $fund] = $this->access->context($expected, $fundId, true);
        $record = $this->access->record($actor, $fund->id, $id, true);
        $fields = [];
        foreach (['ID', 'version', 'N_DFD', 'Data_DFD', 'Data_Conclusao', 'Categoria', 'Requisitante', 'Ficha', ...DfdRegistration::TEXT_FIELDS] as $key) {
            $fields[$key] = $record->$key;
        }
        $items = DB::table('tb_Produtos_DFD')->where('ID_DFD', $id)->whereNull('deleted_at')->orderBy('ITEM')->orderBy('ID')->lockForUpdate()
            ->get(['ID', 'ITEM', 'COD_PROD', 'PROD_SIMPLES', 'CARACT', 'PRODUTO', 'UND', 'QTD'])->map(fn ($row) => (array) $row)->all();
        $department = $record->department_id ? DB::table('contract_departments')->where('id', $record->department_id)->lockForUpdate()->first(['id', 'name']) : null;
        $budgets = filled($record->Ficha) ? CatalogAccess::byOrganization($actor, DB::table('tb_Dotacao')->whereNull('deleted_at'), 'Fundo', 'Secretaria', false)
            ->whereIn('Fundo', [$fund->name, Normalizer::name($fund->name)])->where('Ficha', $record->Ficha)->orderBy('ID_Dotacao')->lockForUpdate()
            ->get(['ID_Dotacao', 'Ficha', 'Secretaria', 'Orgao', 'Gestao', 'Unidade', 'Funcao', 'SubFunc', 'Programa', 'Projeto', 'Fonte', 'Codigo', 'Dotacao'])
            ->filter(fn ($row) => ! $department || blank($row->Secretaria) || Normalizer::name($row->Secretaria) === Normalizer::name($department->name)) : collect();
        abort_if($budgets->count() > 1, 422, 'A ficha da DFD possui mais de uma dotação disponível. Confira o cadastro antes de emitir.');
        $budget = $budgets->first();
        $settings = DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail();

        return ['fields' => $fields, 'items' => $items, 'budget' => $budget ? (array) $budget : null,
            'fund' => ['id' => $fund->id, 'name' => $fund->name],
            'actor' => ['id' => $actor->id, 'username' => $actor->username, 'role' => $actor->user_type],
            'settings' => ['version' => $settings->version, 'administration' => $settings->administration_start_year.'/'.$settings->administration_end_year],
            'template_hash' => hash('sha256', file_get_contents(resource_path('views/documents/dfd.blade.php'))
                .file_get_contents(resource_path('documents/branding/prefeitura.png'))
                .file_get_contents(resource_path('documents/branding/niquelandia-brasao.png')))];
    }

    protected function html(array $snapshot): string
    {
        foreach ($snapshot['items'] as &$item) {
            $description = filled($item['PRODUTO']) ? $item['PRODUTO'] : $item['PROD_SIMPLES'].(filled($item['CARACT']) ? ' — '.$item['CARACT'] : '');
            // PDF table rows cannot span pages; continuation rows keep long items complete.
            $item['segments'] = $this->segments($description);
        }
        unset($item);

        return view('documents.dfd', ['document' => $snapshot,
            'logo' => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('documents/branding/prefeitura.png'))),
            'crestLogo' => 'data:image/png;base64,'.base64_encode(file_get_contents(resource_path('documents/branding/niquelandia-brasao.png')))])->render();
    }

    private function segments(string $text): array
    {
        $segments = [];
        while (mb_strlen($text) > 700) {
            $piece = mb_substr($text, 0, 700);
            $space = mb_strrpos($piece, ' ');
            $length = $space !== false && $space > 350 ? $space + 1 : 700;
            $segments[] = mb_substr($text, 0, $length);
            $text = mb_substr($text, $length);
        }
        $segments[] = $text;

        return $segments;
    }

    protected function render(array $snapshot): string
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($this->html($snapshot), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
