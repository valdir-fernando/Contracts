<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaExtractPeriod;
use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Security\LockActor;
use App\Models\ActivityEvent;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use DateTimeImmutable;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;

class AtaExtractPdf
{
    private const COLUMNS = ['ID', 'ATA', 'version', 'fund_id', 'FUNDO', 'DATA_ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT',
        'FORNECEDOR', 'CNPJ_FORNECEDOR', 'OBJETO DA ATA', 'PERIODO_ATA', 'GESTOR', 'CPF_GESTOR', 'DECRETO_GESTOR', 'CARGO_GESTOR',
        'INICIO_ATA', 'FINAL_ATA', 'VIGENCIA_ANO_INPC'];

    public function __construct(private LockActor $actors, private AtaWritePolicy $policy, private DocumentSettings $settings, private AtaExtractTemplate $template) {}

    public function generate(User $expected, int $ataId, int $version): string
    {
        $templateHash = $this->template->fingerprint();
        $snapshot = DB::transaction(function () use ($expected, $ataId, $version) {
            $actor = $this->actors->lock($expected);
            $ata = $this->authorized($actor, $ataId);
            abort_unless((int) $ata->version === $version, 409, 'A ata foi alterada. Consulte novamente antes de emitir o extrato.');
            $settings = $this->settings->current();

            return ['fields' => $this->fields($ata, $actor, $settings), 'source' => $this->sourceFingerprint($ata),
                'version' => (int) $ata->version, 'role' => $actor->user_type, 'settings' => $this->fingerprint($settings)];
        });
        $bytes = $this->render($this->template->render($snapshot['fields']));
        DB::transaction(function () use ($expected, $ataId, $snapshot, $templateHash, $bytes) {
            $actor = $this->actors->lock($expected);
            $ata = $this->authorized($actor, $ataId);
            abort_unless($actor->user_type === $snapshot['role'], 403);
            abort_unless(hash_equals($snapshot['source'], $this->sourceFingerprint($ata)), 409, 'A ata foi alterada durante a emissão. Gere o extrato novamente.');
            $settings = DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($snapshot['settings'], $this->fingerprint($settings)), 409, 'A administração mudou durante a emissão. Gere o extrato novamente.');
            abort_unless(hash_equals($templateHash, $this->template->fingerprint()), 409, 'O modelo mudou durante a emissão. Gere o extrato novamente.');
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $this->auditAction(), 'created_at' => now(),
                'metadata' => ['ata_id' => $ataId, 'ata_version' => $snapshot['version'], 'model_sha256' => $templateHash,
                    'administration' => $snapshot['fields']['administration'], 'sha256' => hash('sha256', $bytes)]]);
        });

        return $bytes;
    }

    private function authorized(User $actor, int $id): object
    {
        abort_unless($actor->canIssueContracts(), 403);
        $ata = DB::table('tb_ATA')->where('ID', $id)->whereNull('deleted_at')->lockForUpdate()->first(self::COLUMNS);
        abort_unless($ata, 404);
        if ($ata->fund_id === null) {
            abort_unless($actor->isAdmin(), 403);
        } else {
            $this->policy->authorize($actor, (int) $ata->fund_id);
        }

        return $ata;
    }

    private function fingerprint(object $source): string
    {
        return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
    }

    protected function auditAction(): string
    {
        return 'ata_extract_generated';
    }

    protected function sourceFingerprint(object $ata): string
    {
        return $this->fingerprint($ata);
    }

    protected function fields(object $ata, User $actor, object $settings): array
    {
        $text = static fn ($value) => filled($value) ? (string) $value : 'Não informado';
        $months = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $date = $ata->DATA_ATA ? new DateTimeImmutable($ata->DATA_ATA) : null;
        $supplierDocument = Normalizer::document($ata->CNPJ_FORNECEDOR);

        return ['fund' => $text($ata->FUNDO), 'administration' => $settings->administration_start_year.'/'.$settings->administration_end_year,
            'number' => $ata->ATA, 'bidding' => mb_strtoupper($text($ata->LICITACAO)), 'bidding_number' => $ata->N_LICIT, 'process' => $ata->PROCESSO,
            'object' => $text($ata->{'OBJETO DA ATA'}), 'supplier' => $text($ata->FORNECEDOR),
            'supplier_document_label' => strlen($supplierDocument) === 11 ? 'CPF' : 'CNPJ',
            'supplier_document' => $this->document($supplierDocument, $actor),
            'period' => AtaExtractPeriod::text((bool) $ata->VIGENCIA_ANO_INPC, $ata->PERIODO_ATA, $ata->INICIO_ATA, $ata->FINAL_ATA),
            'date' => $date ? $date->format('d').' de '.$months[(int) $date->format('n')].' de '.$date->format('Y') : 'Data não informada',
            'manager' => $text($ata->GESTOR), 'manager_document' => $this->document(Normalizer::document($ata->CPF_GESTOR), $actor),
            'decree' => $text($ata->DECRETO_GESTOR), 'position' => $text($ata->CARGO_GESTOR)];
    }

    private function document(string $digits, User $actor): string
    {
        if (strlen($digits) === 14) {
            return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits);
        }
        if (strlen($digits) === 11) {
            return $actor->isAdmin() ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits) : Normalizer::mask($digits);
        }

        return 'Não informado';
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
