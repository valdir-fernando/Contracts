<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ManagerTableRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_rename_preserves_ids_payload_dates_deleted_records_and_next_id(): void
    {
        $migration = require database_path('migrations/2026_10_18_000013_rename_contract_managers_to_tb_gestor.php');
        $raw = '{"Nome_Gestor":"Gestora de teste","CPF_Gestor":"529.982.247-25","Assinatura":1,"Extra":"preservado"}';
        DB::table('tb_Gestor')->insert(['id' => 25, 'legacy_id' => 32, 'name' => 'Gestora de teste', 'name_key' => 'GESTORA DE TESTE',
            'fund_name_key' => 'MUNICIPIO DE NIQUELANDIA', 'raw_payload' => $raw,
            'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-10-18 11:00:00', 'deleted_at' => '2026-10-18 12:00:00']);
        $before = DB::table('tb_Gestor')->find(25);
        $migration->down();
        $this->assertTrue(Schema::hasTable('contract_managers'));
        $this->assertFalse(Schema::hasTable('tb_Gestor'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('tb_Gestor'));
        $this->assertFalse(Schema::hasTable('contract_managers'));
        $this->assertEquals($before, DB::table('tb_Gestor')->find(25));
        $this->assertSame($raw, DB::table('tb_Gestor')->find(25)->raw_payload);
        $next = DB::table('tb_Gestor')->insertGetId(['name' => 'Outro gestor', 'name_key' => 'OUTRO GESTOR',
            'fund_name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->assertGreaterThan(25, $next);
        $migration->up();
        $this->assertDatabaseCount('tb_Gestor', 2);
    }

    public function test_collision_refuses_to_overwrite_either_table(): void
    {
        Schema::create('contract_managers', function ($table) {
            $table->id();
        });
        DB::table('contract_managers')->insert(['id' => 9]);
        $migration = require database_path('migrations/2026_10_18_000013_rename_contract_managers_to_tb_gestor.php');
        try {
            $migration->up();
            $this->fail('Both existing tables must prevent rename.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('recusada', $error->getMessage());
        }
        $this->assertDatabaseHas('contract_managers', ['id' => 9]);
        $this->assertTrue(Schema::hasTable('tb_Gestor'));
    }
}
