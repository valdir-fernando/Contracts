<?php

namespace App\Domain\Shared;

use InvalidArgumentException;

final class MoneyWords
{
    /** Nonnegative decimal amount already rounded to cents by the caller. */
    public static function reais(string $amount): string
    {
        if (! preg_match('/^\d{1,15}\.\d{2}$/D', $amount)) {
            throw new InvalidArgumentException('Informe um valor decimal com duas casas.');
        }
        [$whole, $fraction] = explode('.', $amount);
        $reais = (int) $whole;
        $cents = (int) $fraction;
        $text = $reais ? self::integer($reais).($reais % 1000000 === 0 ? ' de' : '').($reais === 1 ? ' real' : ' reais') : '';
        if ($cents) {
            $text .= ($text ? ' e ' : '').self::group($cents).($cents === 1 ? ' centavo' : ' centavos');
        }

        return $text ?: 'zero reais';
    }

    private static function integer(int $number): string
    {
        $scales = [['', ''], ['mil', 'mil'], ['milhão', 'milhões'], ['bilhão', 'bilhões'], ['trilhão', 'trilhões']];
        $groups = [];
        for ($index = 0; $number > 0; $index++, $number = intdiv($number, 1000)) {
            $value = $number % 1000;
            if ($value === 0) {
                continue;
            }
            $text = $index === 1 && $value === 1 ? 'mil' : self::group($value).($index ? ' '.$scales[$index][$value === 1 ? 0 : 1] : '');
            array_unshift($groups, ['value' => $value, 'text' => $text]);
        }
        $text = '';
        foreach ($groups as $index => $group) {
            $text .= ($index ? ($index === count($groups) - 1 && ($group['value'] < 100 || $group['value'] % 100 === 0) ? ' e ' : ', ') : '').$group['text'];
        }

        return $text;
    }

    private static function group(int $number): string
    {
        $units = ['zero', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'catorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
        $tens = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
        $hundreds = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
        if ($number < 20) {
            return $units[$number];
        }
        if ($number < 100) {
            return $tens[intdiv($number, 10)].($number % 10 ? ' e '.$units[$number % 10] : '');
        }

        return $number === 100 ? 'cem' : $hundreds[intdiv($number, 100)].($number % 100 ? ' e '.self::group($number % 100) : '');
    }
}
