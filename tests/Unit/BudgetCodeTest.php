<?php

namespace Tests\Unit;

use App\Domain\Contracts\BudgetCode;
use PHPUnit\Framework\TestCase;

class BudgetCodeTest extends TestCase
{
    public function test_reference_code_preserves_leading_zeros_and_starts_with_management(): void
    {
        $fields = ['Orgao' => '02', 'Gestao' => '02', 'Unidade' => '0217', 'Funcao' => '04', 'SubFunc' => '122', 'Programa' => '7008',
            'Projeto' => '8040', 'Codigo' => '319094', 'Fonte' => '100', 'Ficha' => '20250029'];
        $this->assertSame('02.0217.04.122.7008.8040.319094(100)20250029', BudgetCode::compose($fields));
        $this->assertSame(BudgetCode::compose($fields), BudgetCode::compose(array_replace($fields, ['Orgao' => '11'])));
        $this->assertSame('08.0217.04.122.7008.8040.319094(100)20250029', BudgetCode::compose(array_replace($fields, ['Gestao' => '08'])));
    }
}
