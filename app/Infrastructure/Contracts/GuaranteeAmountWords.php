<?php

namespace App\Infrastructure\Contracts;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use NumberFormatter;

final class GuaranteeAmountWords
{
    public static function format(?string $amount): ?string
    {
        if ($amount === null) {
            return null;
        }
        $rounded = (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
        $negative = str_starts_with($rounded, '-');
        [$whole, $fraction] = explode('.', ltrim($rounded, '-'));
        $reais = (int) $whole;
        $cents = (int) $fraction;
        $formatter = new NumberFormatter('pt_BR', NumberFormatter::SPELLOUT);
        $text = $reais ? $formatter->format($reais).($reais % 1000000 === 0 ? ' de' : '').($reais === 1 ? ' real' : ' reais') : '';
        if ($cents) {
            $text .= ($text !== '' ? ' e ' : '').$formatter->format($cents).($cents === 1 ? ' centavo' : ' centavos');
        }

        return ($negative && ($reais || $cents) ? 'menos ' : '').($text !== '' ? $text : 'zero reais');
    }
}
