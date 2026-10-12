<?php

namespace Tests\Unit;

use App\Domain\Shared\BrazilianDocument;
use App\Domain\Shared\DecimalAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizationTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            [null, null], ['', null], ['1.908,6300', '1908.6300'], ['1908.6300', '1908.6300'],
            ['00001,2', '1.2000'], ['-0.0000', '0.0000'], ['-1908,6300', '-1908.6300'],
            ['99999999999999.9999', '99999999999999.9999'], ['100000000000000', null],
            ['1.00001', null], ['1,00001', null], ['1,2,3', null], ['1e3', null], ['=1+1', null],
        ];
    }

    #[DataProvider('amounts')]
    public function test_decimal_conversion_is_exact_and_retains_existing_legacy_conventions(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, DecimalAmount::normalize($input));
    }

    public static function documents(): array
    {
        return [['529.982.247-25', '52998224725', true], ['11.222.333/0001-81', '11222333000181', true], ['11111111111', '11111111111', false], ['52998224726', '52998224726', false], [null, '', false]];
    }

    #[DataProvider('documents')]
    public function test_document_digits_and_verifiers_do_not_need_laravel(?string $input, string $digits, bool $valid): void
    {
        $this->assertSame($digits, BrazilianDocument::document($input));
        $this->assertSame($valid, BrazilianDocument::validDocument($digits));
    }

    public function test_personal_document_mask_preserves_only_the_last_four_digits(): void
    {
        $this->assertSame('•••4725', BrazilianDocument::mask('529.982.247-25'));
        $this->assertSame('•••', BrazilianDocument::mask(null));
    }
}
