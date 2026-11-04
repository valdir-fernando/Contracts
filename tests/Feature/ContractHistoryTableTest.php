<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractHistoryTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_preserves_text_and_generates_unique_sequential_ids(): void
    {
        $entry = [
            'Contrato' => '001/26',
            'Fundo' => 'Fundo Municipal de Saúde',
            'Historico' => str_repeat("Registro da fiscalização.\n", 100),
            'Fiscal' => 'Fiscal responsável',
        ];

        $first = DB::table('tb_Historico')->insertGetId($entry, 'ID');
        $second = DB::table('tb_Historico')->insertGetId($entry, 'ID');

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first + 1, $second);
        $this->assertDatabaseHas('tb_Historico', ['ID' => $first] + $entry);

        $migration = require database_path('migrations/2026_11_03_000001_create_tb_historico.php');
        $migration->down();

        $this->assertDatabaseCount('tb_Historico', 2);
    }
}
