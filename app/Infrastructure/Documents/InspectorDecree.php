<?php

namespace App\Infrastructure\Documents;

use App\Infrastructure\Inspectors\InspectorRegistration;
use App\Infrastructure\Managers\ManagerSignatory;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InspectorDecree
{
    public function __construct(private LockActor $actors) {}

    public function prepare(User $expected): array
    {
        return DB::transaction(function () use ($expected) {
            $actor = $this->actors->lock($expected);
            abort_unless($actor->isAdmin(), 403);
            $snapshot = $this->snapshot();
            $expires = now()->addMinutes(30)->timestamp;

            return ['inspectors' => $snapshot['inspectors'], 'expires' => $expires,
                'revision' => $this->revision($snapshot, $actor, $expires)];
        }, 3);
    }

    public function generate(User $expected, array $input): string
    {
        $snapshot = DB::transaction(function () use ($expected, $input) {
            $actor = $this->actors->lock($expected);
            abort_unless($actor->isAdmin(), 403);
            $data = Validator::make($input, [
                'revision' => 'required|string|size:64', 'expires' => 'required|integer',
                'inspector_id' => 'required|integer|min:1',
            ])->validate();
            abort_unless((int) $data['expires'] >= now()->timestamp, 409, 'A preparação do decreto expirou. Reabra o botão Decreto.');
            $snapshot = $this->snapshot();
            abort_unless(hash_equals($this->revision($snapshot, $actor, (int) $data['expires']), $data['revision']), 409,
                'Os cadastros ou modelos mudaram. Reabra o botão Decreto.');
            $anchor = collect($snapshot['inspectors'])->firstWhere('id', (int) $data['inspector_id']);
            abort_unless($anchor, 422, 'O fiscal selecionado não está disponível para emissão.');
            abort_unless(filled($anchor['number']) && $anchor['date'] && ! $anchor['invalid_date'], 422,
                'Informe e salve o número e uma data válida do decreto no cadastro do fiscal antes de emitir.');
            $selected = array_values(array_filter($snapshot['inspectors'], fn ($row) => mb_strtoupper(trim($row['number'])) === mb_strtoupper(trim($anchor['number'])) && $row['date'] === $anchor['date']));
            $unavailable = collect($selected)->filter(fn ($row) => ! $row['available'])->pluck('name')->implode('; ');
            abort_if($unavailable !== '', 422,
                'Os seguintes fiscais deste decreto possuem Fundo ou Secretaria indisponível ou ambíguo: '.$unavailable.'. Corrija os vínculos antes de emitir.');
            $data['number'] = trim($anchor['number']);
            $data['date'] = $anchor['date'];
            $data['mode'] = count($selected) > 1 ? 'collective' : 'individual';

            return ['source' => $snapshot, 'data' => $data, 'selected' => $selected];
        }, 3);
        $bytes = $this->render($this->html($snapshot));
        DB::transaction(function () use ($expected, $snapshot, $bytes) {
            $actor = $this->actors->lock($expected);
            abort_unless($actor->isAdmin(), 403);
            $data = $snapshot['data'];
            abort_unless((int) $data['expires'] >= now()->timestamp
                && hash_equals($data['revision'], $this->revision($this->snapshot(), $actor, (int) $data['expires'])), 409,
                'Os dados mudaram durante a emissão. Reabra o botão Decreto.');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => 'inspector_decree_generated',
                'metadata' => ['inspector_ids' => array_column($snapshot['selected'], 'id'), 'mode' => $data['mode'],
                    'number' => $data['number'], 'date' => $data['date'], 'source_fingerprint' => $data['revision'],
                    'sha256' => hash('sha256', $bytes)], 'created_at' => now()]);
        }, 3);

        return $bytes;
    }

    public function generateForInspector(User $expected, int $id, string $revision): string
    {
        $prepared = $this->prepare($expected);
        $record = collect($prepared['inspectors'])->firstWhere('id', $id);
        abort_unless($record, 422, 'O fiscal selecionado não está disponível para emissão.');
        abort_unless(hash_equals($record['revision'], $revision), 409, 'O fiscal foi alterado. Reabra o cadastro antes de emitir.');

        return $this->generate($expected, ['inspector_id' => $id, 'revision' => $prepared['revision'], 'expires' => $prepared['expires']]);
    }

    private function snapshot(): array
    {
        $funds = DB::table('contract_funds')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get();
        $municipal = $funds->filter(fn ($fund) => ManagerSignatory::isMunicipal($fund->name));
        abort_unless($municipal->count() === 1, 422, 'Cadastre um único Município de Niquelândia antes de emitir decretos.');
        $fund = $municipal->first();
        $raw = json_decode($fund->raw_payload, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(filled($fund->document) && filled($raw['End_Fundo'] ?? null), 422, 'Complete o CNPJ e o endereço do Município.');
        $mayors = DB::table('tb_Gestor')->whereNull('deleted_at')->where('fund_name_key', $fund->name_key)
            ->orderBy('id')->lockForUpdate()->get()->filter(function ($record) {
                $raw = json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR);

                return in_array(Normalizer::name($raw['Cargo'] ?? ''), ['PREFEITO', 'PREFEITO MUNICIPAL', 'PREFEITA', 'PREFEITA MUNICIPAL'], true)
                    && Normalizer::name($raw['Situacao'] ?? 'Ativo') !== 'INATIVO';
            });
        abort_unless($mayors->count() === 1, 422, 'Cadastre um único Prefeito ativo no Município para assinar os decretos.');
        $rows = DB::table('contract_inspectors')->whereNull('deleted_at')->orderBy('id')->lockForUpdate()->get();
        $departments = DB::table('contract_departments')->orderBy('id')->lockForUpdate()->get();
        $inspectors = [];
        foreach ($rows as $row) {
            $payload = json_decode($row->raw_payload, true, 512, JSON_THROW_ON_ERROR);
            if (Normalizer::name($payload['Situacao'] ?? 'Ativo') === 'INATIVO') {
                continue;
            }
            $matches = $funds->where('name_key', $row->fund_name_key);
            $department = $departments->where('name_key', $row->department_name_key);
            $municipal = $matches->count() === 1 && ManagerSignatory::isMunicipal($matches->first()->name);
            $available = $matches->count() === 1 && (! $municipal || ! $row->department_name_key || $department->count() === 1);
            $inspectors[] = ['id' => (int) $row->id, 'name' => mb_strtoupper($row->name),
                'available' => $available,
                'number' => (string) ($payload['Decreto'] ?? ''),
                'date' => InspectorRegistration::dateForForm($payload['Data_Decreto'] ?? null),
                'invalid_date' => filled($payload['Data_Decreto'] ?? null) && ! InspectorRegistration::dateForForm($payload['Data_Decreto']),
                'unit' => ! $available ? 'Vínculo indisponível' : ($municipal && $row->department_name_key
                    ? $department->first()->name : $matches->first()->name),
                'revision' => hash_hmac('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR), config('app.key'))];
        }

        return ['inspectors' => $inspectors, 'municipality' => ['cnpj' => $fund->document, 'address' => $raw['End_Fundo']],
            'mayor' => mb_strtoupper($mayors->first()->name),
            'settings' => (array) DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail(),
            'sources' => hash('sha256', json_encode([$funds, $mayors->values(), $departments, $rows], JSON_THROW_ON_ERROR)),
            'models' => [hash_file('sha256', $this->path('individual')), hash_file('sha256', $this->path('collective'))]];
    }

    private function revision(array $snapshot, User $actor, int $expires): string
    {
        return hash_hmac('sha256', json_encode([$snapshot, $actor->id, $actor->session_version, $expires], JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function path(string $mode): string
    {
        return resource_path('documents/decrees/'.($mode === 'individual' ? 'fiscal-individual' : 'fiscais-coletivo').'.md');
    }

    private function html(array $snapshot): string
    {
        $data = $snapshot['data'];
        $source = $snapshot['source'];
        $markdown = file_get_contents($this->path($data['mode']));
        $fields = ['municipio.cnpj' => $source['municipality']['cnpj'], 'municipio.endereco' => $source['municipality']['address'],
            'administracao' => $source['settings']['administration_start_year'].'/'.$source['settings']['administration_end_year'],
            'prefeito.nome' => $source['mayor'], 'decreto.numero' => $data['number'],
            'decreto.data_por_extenso' => CarbonImmutable::createFromFormat('!Y-m-d', $data['date'])->locale('pt_BR')->translatedFormat('d \d\e F \d\e Y')];
        if ($data['mode'] === 'collective') {
            $line = '| {{fiscal.nome}} | {{fiscal.unidade}} |';
            $lines = [];
            foreach ($snapshot['selected'] as $index => $row) {
                $lines[] = str_replace('fiscal.', 'fiscal'.$index.'.', $line);
                foreach (['nome' => 'name', 'unidade' => 'unit'] as $key => $column) {
                    $fields['fiscal'.$index.'.'.$key] = $row[$column];
                }
            }
            $markdown = str_replace($line, implode("\n", $lines), $markdown);
            $markdown = preg_replace('/\nUma linha por fiscal designado\..*$/s', '', $markdown);
        } else {
            $row = $snapshot['selected'][0];
            $fields += ['fiscal.nome' => $row['name'], 'designacao.unidade' => $row['unit']];
        }
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $replace = [];
        foreach ($fields as $key => $value) {
            $replace['{{'.$key.'}}'] = e($value);
        }

        return '<html><meta charset="UTF-8"><style>@page{margin:2cm}body{font-family:DejaVu Sans;font-size:10pt;line-height:1.5}h1{font-size:13pt;text-align:center}h2{font-size:11pt}p{text-align:justify}table{border-collapse:collapse;width:100%;font-size:8pt}td,th{border:1px solid #777;padding:5px;overflow-wrap:break-word}thead{display:table-header-group}</style><body>'.strtr($html, $replace).'</body></html>';
    }

    protected function render(string $html): string
    {
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
