<?php

namespace Tests\Unit;

use App\Domain\Shared\MoneyWords;
use PHPUnit\Framework\TestCase;

class MoneyWordsTest extends TestCase
{
    public function test_exact_monetary_words_include_currency_scales_and_cents(): void
    {
        foreach ([
            '0.00' => 'zero reais', '0.01' => 'um centavo', '1.01' => 'um real e um centavo',
            '100.00' => 'cem reais', '101.10' => 'cento e um reais e dez centavos',
            '1000.00' => 'mil reais', '1200.00' => 'mil e duzentos reais',
            '1234.51' => 'mil, duzentos e trinta e quatro reais e cinquenta e um centavos',
            '1000000.00' => 'um milhão de reais', '2000000000.00' => 'dois bilhões de reais',
            '99999999999999.99' => 'noventa e nove trilhões, novecentos e noventa e nove bilhões, novecentos e noventa e nove milhões, novecentos e noventa e nove mil, novecentos e noventa e nove reais e noventa e nove centavos',
        ] as $amount => $expected) {
            $this->assertSame($expected, MoneyWords::reais($amount));
        }
    }
}
