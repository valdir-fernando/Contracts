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
use Illuminate\Validation\ValidationException;

class AtaExtractPdf
{
    private const COLUMNS = ['ID', 'ATA', 'version', 'fund_id', 'FUNDO', 'DATA_ATA', 'PROCESSO', 'LICITACAO', 'N_LICIT',
        'FORNECEDOR', 'CNPJ_FORNECEDOR', 'OBJETO DA ATA', 'PERIODO_ATA', 'GESTOR', 'CPF_GESTOR', 'DECRETO_GESTOR', 'CARGO_GESTOR',
        'MATRICULA_GESTOR', 'RG_GESTOR',
        'INICIO_ATA', 'FINAL_ATA', 'VIGENCIA_ANO_INPC'];

    public function __construct(private LockActor $actors, private AtaWritePolicy $policy, private DocumentSettings $settings, private AtaExtractTemplate $template) {}

    public function generate(User $expected, int $ataId, int $version): string
    {
        return $this->generateDocument($expected, $ataId, $version);
    }

    protected function generateDocument(User $expected, int $ataId, int $version, array $presentation = []): string
    {
        return $this->generateDocumentResult($expected, $ataId, $version, $presentation)['bytes'];
    }

    /** @return array{bytes: string, number: string, supplier: string} */
    protected function generateDocumentResult(User $expected, int $ataId, int $version, array $presentation): array
    {
        $templateHash = $this->template->fingerprint();
        $document = $this->documentName();
        $snapshot = DB::transaction(function () use ($expected, $ataId, $version, $document, $presentation) {
            $actor = $this->actors->lock($expected);
            $ata = $this->authorized($actor, $ataId);
            abort_unless((int) $ata->version === $version, 409, "A ata foi alterada. Consulte novamente antes de emitir {$document}.");
            $settings = $this->settings->current();

            $related = $this->relatedSource($ata, $presentation);

            return ['fields' => $this->fields($ata, $actor, $settings), 'related' => $related, 'source' => $this->sourceFingerprint($ata),
                'version' => (int) $ata->version, 'access' => $this->documentAccess($actor), 'settings' => $this->fingerprint($settings)];
        });
        $bytes = $this->render($this->template->render($snapshot['fields'] + ['presentation' => $presentation, 'related' => $snapshot['related']]));
        DB::transaction(function () use ($expected, $ataId, $snapshot, $templateHash, $bytes, $document, $presentation) {
            $actor = $this->actors->lock($expected);
            $ata = $this->authorized($actor, $ataId);
            abort_unless($this->documentAccess($actor) === $snapshot['access'], 403);
            abort_unless(hash_equals($snapshot['source'], $this->sourceFingerprint($ata)), 409, "A ata foi alterada durante a emissão. Gere {$document} novamente.");
            abort_unless($snapshot['related'] === $this->relatedSource($ata, $presentation), 409, "Os dados vinculados mudaram durante a emissão. Gere {$document} novamente.");
            $settings = DB::table('document_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($snapshot['settings'], $this->fingerprint($settings)), 409, "A administração mudou durante a emissão. Gere {$document} novamente.");
            abort_unless(hash_equals($templateHash, $this->template->fingerprint()), 409, "O modelo mudou durante a emissão. Gere {$document} novamente.");
            ActivityEvent::create(['actor_id' => $actor->id, 'action' => $this->auditAction(), 'created_at' => now(),
                'metadata' => ['ata_id' => $ataId, 'ata_version' => $snapshot['version'], 'model_sha256' => $templateHash,
                    'administration' => $snapshot['fields']['administration'], 'sha256' => hash('sha256', $bytes),
                    ...($presentation ? ['presentation' => $presentation] : [])]]);
        });

        return ['bytes' => $bytes, 'number' => $snapshot['fields']['number'], 'supplier' => $snapshot['fields']['supplier']];
    }

    private function authorized(User $actor, int $id): object
    {
        abort_unless($actor->canIssueContracts(), 403);
        $ata = DB::table('tb_ATA')->where('ID', $id)->whereNull('deleted_at')->lockForUpdate()->first([...self::COLUMNS, ...$this->additionalColumns()]);
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

    protected function documentName(): string
    {
        return 'o extrato';
    }

    protected function additionalColumns(): array
    {
        return [];
    }

    protected function documentAccess(User $actor): array
    {
        return [$actor->user_type];
    }

    protected function sourceFingerprint(object $ata): string
    {
        return $this->fingerprint($ata);
    }

    protected function relatedSource(object $ata, array $presentation): array
    {
        return [];
    }

    protected function fields(object $ata, User $actor, object $settings): array
    {
        $text = static fn ($value) => filled($value) ? (string) $value : 'Não informado';
        $supplierDocument = Normalizer::document($ata->CNPJ_FORNECEDOR);

        return ['fund' => $text($ata->FUNDO), 'administration' => $settings->administration_start_year.'/'.$settings->administration_end_year,
            'number' => $ata->ATA, 'bidding' => mb_strtoupper($text($ata->LICITACAO)), 'bidding_number' => $ata->N_LICIT, 'process' => $ata->PROCESSO,
            'object' => $text($ata->{'OBJETO DA ATA'}), 'supplier' => $text($ata->FORNECEDOR),
            'supplier_document_label' => strlen($supplierDocument) === 11 ? 'CPF' : 'CNPJ',
            'supplier_document' => $this->document($supplierDocument, $actor),
            'period' => AtaExtractPeriod::text((bool) $ata->VIGENCIA_ANO_INPC, $ata->PERIODO_ATA, $ata->INICIO_ATA, $ata->FINAL_ATA),
            'date' => self::longDate($ata->DATA_ATA),
            'manager' => $text($ata->GESTOR), 'manager_identification' => $this->managerIdentification($ata, $actor),
            'position' => trim($ata->CARGO_GESTOR ?? '')];
    }

    protected static function longDate(?string $value): string
    {
        $months = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        $date = $value ? new DateTimeImmutable($value) : null;

        return $date ? $date->format('d').' de '.$months[(int) $date->format('n')].' de '.$date->format('Y') : 'Data não informada';
    }

    private function managerIdentification(object $ata, User $actor): string
    {
        $cpf = Normalizer::document($ata->CPF_GESTOR);
        if (strlen($cpf) === 11 && Normalizer::validDocument($cpf)) {
            return 'CPF: '.$this->document($cpf, $actor);
        }

        $decree = trim(preg_replace('/^Decreto\b(?:\s+Municipal)?\s*(?:n[º°o.]\s*)?/iu', '', trim($ata->DECRETO_GESTOR ?? '')));
        if ($decree !== '' && trim($ata->CARGO_GESTOR ?? '') !== '') {
            return 'Decreto Municipal: '.$decree;
        }

        $registration = trim($ata->MATRICULA_GESTOR ?? '');
        if ($registration !== '') {
            return 'Matrícula Funcional: '.$registration;
        }

        $rg = trim($ata->RG_GESTOR ?? '');
        if ($rg !== '') {
            return 'RG: '.($actor->isAdmin() ? $rg : Normalizer::mask($rg));
        }

        throw ValidationException::withMessages([
            'manager_document' => 'Não foi possível emitir o documento da ATA. Informe um CPF válido, um Decreto Municipal com o cargo do gestor, uma Matrícula Funcional ou um RG nos dados do gestor e consulte a ATA novamente.',
        ]);
    }

    protected function document(string $digits, User $actor): string
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
        $this->configureRenderer($pdf);
        $pdf->render();

        return $pdf->output();
    }

    protected function configureRenderer(Dompdf $pdf): void {}
}
