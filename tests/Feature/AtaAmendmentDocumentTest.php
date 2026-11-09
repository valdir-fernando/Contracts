<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AtaAmendmentDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_bytes_larger_than_a_regular_blob_are_preserved_without_text_conversion(): void
    {
        $bytes = "%PDF-1.7\n".random_bytes(131072)."\x00\xff\n%%EOF\n";
        $id = DB::table('tb_ADT_ATA')->insertGetId(['ATA' => '003/2025', 'ADT' => 1,
            'Proc_ADT' => '2026000001', 'Doc_ADT' => $bytes, 'Período' => '12 meses'], 'ID');
        $stored = DB::table('tb_ADT_ATA')->where('ID', $id)->first();
        $this->assertSame(strlen($bytes), strlen($stored->Doc_ADT));
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $stored->Doc_ADT));
        $this->assertSame('12 meses', $stored->{'Período'});
    }

    public function test_migration_rejects_existing_paths_instead_of_treating_them_as_pdf_content(): void
    {
        DB::table('tb_ADT_ATA')->insert(['ATA' => '003/2025', 'ADT' => 1, 'Doc_ADT' => 'aditivos/1.pdf']);
        $migration = require database_path('migrations/2026_11_09_000008_store_ata_amendment_pdf_binary.php');
        $this->expectExceptionMessage('Doc_ADT contém referências existentes');
        $migration->up();
    }
}
