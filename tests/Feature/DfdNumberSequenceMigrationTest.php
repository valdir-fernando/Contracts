<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DfdNumberSequenceMigrationTest extends TestCase
{
    public function test_migration_preserves_legacy_counter_without_inventing_a_year(): void
    {
        Schema::create('tb_N_DFD', function (Blueprint $table) {
            $table->increments('ID');
            $table->integer('N_DFD');
        });
        DB::table('tb_N_DFD')->insert(['ID' => 1, 'N_DFD' => 5]);
        $migration = require database_path('migrations/2026_10_29_000002_create_dfd_number_sequences.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('tb_N_DFD', ['ID' => 1, 'N_DFD' => 5, 'ANO' => null]);
        DB::table('tb_N_DFD')->insert(['ANO' => 2026, 'N_DFD' => 10]);
        $migration->down();
        $this->assertDatabaseCount('tb_N_DFD', 2);
        $this->assertDatabaseHas('tb_N_DFD', ['ANO' => 2026, 'N_DFD' => 10]);
    }

    public function test_database_enforces_one_counter_per_year_across_all_funds(): void
    {
        $migration = require database_path('migrations/2026_10_29_000002_create_dfd_number_sequences.php');
        $migration->up();
        DB::table('tb_N_DFD')->insert(['ANO' => 2026, 'N_DFD' => 1]);
        DB::table('tb_N_DFD')->insert(['ANO' => 2027, 'N_DFD' => 1]);
        $this->assertDatabaseCount('tb_N_DFD', 2);
        $this->expectException(QueryException::class);
        DB::table('tb_N_DFD')->insert(['ANO' => 2026, 'N_DFD' => 2]);
    }
}
