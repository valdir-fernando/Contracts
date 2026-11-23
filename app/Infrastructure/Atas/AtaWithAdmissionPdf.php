<?php

namespace App\Infrastructure\Atas;

use App\Domain\Atas\AtaExtractPeriod;
use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Security\LockActor;
use App\Models\User;
use App\Services\Legacy\Normalizer;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\FrameDecorator\Text;
use Illuminate\Support\Facades\DB;

class AtaWithAdmissionPdf extends AtaExtractPdf
{
    public function generateWithLayout(User $expected, int $ataId, int $version, string $layout): string
    {
        abort_unless(in_array($layout, ['continuous', 'parallel'], true), 422);

        return $this->generateDocument($expected, $ataId, $version, ['products_layout' => $layout]);
    }

    public function __construct(LockActor $actors, AtaWritePolicy $policy, DocumentSettings $settings, AtaWithAdmissionTemplate $template)
    {
        parent::__construct($actors, $policy, $settings, $template);
    }

    protected function auditAction(): string
    {
        return 'ata_with_admission_generated';
    }

    protected function documentName(): string
    {
        return 'a ata';
    }

    protected function additionalColumns(): array
    {
        return ['ADMITIR_ADESAO', 'QTD_ESPECIAL_NOME', 'CNPJ_FUNDO', 'END_FUNDO',
            'END_FORNECEDOR', 'EMAIL', 'TELEFONE1', 'SOCIO', 'RG_SOCIO', 'CPF_SOCIO', 'RG_FORNECEDOR'];
    }

    protected function documentAccess(User $actor): array
    {
        return [...parent::documentAccess($actor), $actor->canAccessCatalog('supplier')];
    }

    protected function sourceFingerprint(object $ata): string
    {
        return hash('sha256', parent::sourceFingerprint($ata).json_encode([
            $this->products((int) $ata->ID), $this->participants($ata),
        ], JSON_THROW_ON_ERROR));
    }

    protected function fields(object $ata, User $actor, object $settings): array
    {
        abort_unless($ata->ADMITIR_ADESAO === 'SIM', 422, 'O modelo de ATA está disponível quando Admitir Adesão a ATA está em SIM.');

        $text = static fn ($value) => filled($value) ? (string) $value : 'Não informado';
        $fields = parent::fields($ata, $actor, $settings);
        $individual = $fields['supplier_document_label'] === 'CPF';
        $rg = $individual ? $ata->RG_FORNECEDOR : $ata->RG_SOCIO;
        $fields['bidding'] = $text($ata->LICITACAO);
        $fields['period'] = AtaExtractPeriod::duration((bool) $ata->VIGENCIA_ANO_INPC, $ata->PERIODO_ATA, $ata->INICIO_ATA, $ata->FINAL_ATA);

        return $fields + [
            'fund_document' => $this->document(Normalizer::document($ata->CNPJ_FUNDO), $actor),
            'fund_address' => $text($ata->END_FUNDO),
            'supplier_address' => $text($ata->END_FORNECEDOR),
            'supplier_email' => $text($actor->canAccessCatalog('supplier') ? $ata->EMAIL : null),
            'supplier_phone' => $text($actor->canAccessCatalog('supplier') ? $ata->TELEFONE1 : null),
            'representative' => $individual ? $fields['supplier'] : $text($ata->SOCIO),
            'representative_rg' => $text($actor->isAdmin() ? $rg : Normalizer::mask($rg)),
            'representative_cpf' => $individual ? $fields['supplier_document'] : $this->document(Normalizer::document($ata->CPF_SOCIO), $actor),
            'participants' => $this->participants($ata),
            'special_quantity_name' => trim($ata->QTD_ESPECIAL_NOME ?? ''),
            'products' => array_map(fn ($row) => [
                'item' => $row->ITEM, 'code' => $row->COD_PROD, 'description' => $row->DISCRIMINACAO, 'unit' => $row->UND,
                'special_quantity' => $this->decimal($row->QTD_ESPECIAL), 'quantity' => $this->decimal($row->QTD),
                'brand' => $row->MARCA, 'unit_price' => $this->decimal($row->VL_UNIT), 'total' => $this->decimal($row->VL_TOTAL),
            ], $this->products((int) $ata->ID)),
        ];
    }

    private function products(int $ataId): array
    {
        return DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)->where('ADITIVO', 0)
            ->whereNull('ID_ADITIVO')->whereNull('deleted_at')->orderBy('ID')->lockForUpdate()
            ->get(['ID', 'ITEM', 'COD_PROD', 'DISCRIMINACAO', 'UND', 'QTD_ESPECIAL', 'QTD', 'MARCA', 'VL_UNIT', 'VL_TOTAL'])->all();
    }

    private function participants(object $ata): array
    {
        $managerDocument = Normalizer::document($ata->CNPJ_FUNDO);

        return DB::table('tb_FUNDOS_ATA')->where('ID_ATA', $ata->ID)->whereNull('deleted_at')->orderBy('ID')->lockForUpdate()
            ->get(['FUNDO', 'fund_id', 'CNPJ_FUNDO', 'papel'])
            ->reject(fn ($row) => $row->papel === 'gerenciador'
                || ($ata->fund_id !== null && (int) $row->fund_id === (int) $ata->fund_id)
                || ($managerDocument !== '' && Normalizer::document($row->CNPJ_FUNDO) === $managerDocument))
            ->pluck('FUNDO')->filter(fn ($name) => filled($name))->values()->all();
    }

    private function decimal(string|int|float|null $value): string
    {
        $amount = DecimalAmount::normalize((string) $value);
        if ($amount === null) {
            return '—';
        }
        [$whole, $fraction] = explode('.', $amount);

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).','.$fraction;
    }

    protected function configureRenderer(Dompdf $pdf): void
    {
        $pagePositions = [];
        $pdf->setCallbacks([
            ['event' => 'end_frame', 'f' => static function (Frame $frame, Canvas $canvas) use (&$pagePositions): void {
                $node = $frame->get_node();
                if ($node instanceof DOMElement && $node->hasAttribute('data-ata-pagination')
                    && ($text = $frame->get_last_child()) instanceof Text) {
                    [$left, $top] = $text->get_position();
                    $pagePositions[$canvas->get_page_number()] = [$left + (float) $text->get_style()->width + 3, $top];
                }
            }],
            ['event' => 'end_document', 'f' => static function (int $page, int $count, Canvas $canvas, FontMetrics $metrics) use (&$pagePositions): void {
                [$x, $top] = $pagePositions[$page];
                $regular = $metrics->getFont('Helvetica', 'normal');
                $bold = $metrics->getFont('Helvetica', 'bold');
                $parts = [['| Pág. ', $regular], [(string) $page, $bold], [' de ', $regular], [(string) $count, $bold]];
                foreach ($parts as [$text, $font]) {
                    $canvas->text($x, $top, $text, $font, 9);
                    $x += $metrics->getTextWidth($text, $font, 9);
                }
            }],
        ]);
    }
}
