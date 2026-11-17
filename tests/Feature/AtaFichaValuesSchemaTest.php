<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtaFichaValuesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_table_is_archived_with_fields_and_identifiers_preserved(): void
    {
        $this->assertFalse(Schema::hasTable('tb_ValorFicha_ATA'));
        $this->assertEqualsCanonicalizing(['ATA', 'Processo', 'Ficha', 'VALOR'], Schema::getColumnListing('tb_ValorFicha_ATA_historico'));
        Schema::rename('tb_ValorFicha_ATA_historico', 'tb_ValorFicha_ATA');
        DB::table('tb_ValorFicha_ATA')->insert(['ATA' => '107/25', 'Processo' => '000123/2025', 'Ficha' => '00042', 'VALOR' => '1234.5678']);
        $migration = require database_path('migrations/2026_11_15_000002_retire_ata_ficha_values.php');
        $migration->up();
        $this->assertFalse(Schema::hasTable('tb_ValorFicha_ATA'));
        $row = DB::table('tb_ValorFicha_ATA_historico')->sole();
        $this->assertSame('107/25', $row->ATA);
        $this->assertSame('000123/2025', $row->Processo);
        $this->assertSame('00042', $row->Ficha);
        $this->assertSame('1234.5678', (string) $row->VALOR);
    }

    public function test_repeat_and_rollback_preserve_new_values_and_original_contract_catalog(): void
    {
        DB::table('tb_ValorFicha')->insert(['Contrato' => '001/26', 'Ficha' => '9', 'Valor' => '50.0000']);
        DB::table('tb_ValorFicha_ATA_historico')->insert(['ATA' => '001/26', 'Processo' => 'PROC', 'Ficha' => '9', 'VALOR' => '0.0001']);
        $migration = require database_path('migrations/2026_11_15_000002_retire_ata_ficha_values.php');
        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasTable('tb_ValorFicha_ATA'));
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 1);
        $this->assertDatabaseHas('tb_ValorFicha_ATA_historico', ['ATA' => '001/26', 'Processo' => 'PROC', 'Ficha' => '9', 'VALOR' => '0.0001']);
        $this->assertDatabaseHas('tb_ValorFicha', ['Contrato' => '001/26', 'Ficha' => '9', 'Valor' => '50.0000']);
    }
}
