<?php

namespace App\Infrastructure\Atas;

use App\Domain\Shared\DecimalAmount;
use App\Domain\Shared\MoneyWords;
use App\Infrastructure\Documents\DocumentSettings;
use App\Infrastructure\Security\LockActor;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class AtaPublicationPdf extends AtaExtractPdf
{
    public function __construct(LockActor $actors, AtaWritePolicy $policy, DocumentSettings $settings, AtaPublicationTemplate $template)
    {
        parent::__construct($actors, $policy, $settings, $template);
    }

    protected function auditAction(): string
    {
        return 'ata_publication_generated';
    }

    private function products(int $ataId): array
    {
        return DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $ataId)->where('ADITIVO', 0)
            ->whereNull('ID_ADITIVO')->whereNull('deleted_at')->orderBy('ID')->lockForUpdate()
            ->get(['ID', 'VL_TOTAL'])->map(fn ($row) => (array) $row)->all();
    }

    protected function sourceFingerprint(object $ata): string
    {
        return hash('sha256', parent::sourceFingerprint($ata).json_encode($this->products((int) $ata->ID), JSON_THROW_ON_ERROR));
    }

    protected function fields(object $ata, User $actor, object $settings): array
    {
        $fields = parent::fields($ata, $actor, $settings);
        $products = $this->products((int) $ata->ID);
        $total = BigDecimal::zero();
        foreach ($products as $product) {
            $value = DecimalAmount::normalize((string) $product['VL_TOTAL']);
            abort_if($value === null || str_starts_with($value, '-'), 422, 'Confira os valores dos produtos antes de emitir a publicação.');
            $total = $total->plus($value);
        }
        $amount = (string) $total->toScale(2, RoundingMode::HalfUp);
        [$whole, $fraction] = explode('.', $amount);
        $fields['amount'] = $products ? 'R$ '.preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).','.$fraction : 'Não informado';
        $fields['amount_words'] = $products ? MoneyWords::reais($amount) : 'Não informado';
        $fields['publication_date'] = $ata->DATA_ATA ? (new \DateTimeImmutable($ata->DATA_ATA))->format('d/m/Y') : 'Não informado';

        return $fields;
    }
}
