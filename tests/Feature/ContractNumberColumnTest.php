<?php

namespace Tests\Feature;

use App\Infrastructure\Contracts\ContractMapper;
use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContractNumberColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_uses_contrato_and_model_and_domain_keep_the_existing_number_contract(): void
    {
        $this->assertTrue(Schema::hasColumn('contracts', 'Contrato'));
        $this->assertFalse(Schema::hasColumn('contracts', 'numero'));
        $model = Contract::create(['numero' => '005/25', 'quality_issues' => []])->fresh();
        $this->assertSame('005/25', DB::table('contracts')->where('id', $model->id)->value('Contrato'));
        $this->assertSame('005/25', $model->numero);
        $state = ContractMapper::toDomain($model);
        $this->assertSame('005/25', $state->get('numero'));
        $this->assertArrayNotHasKey('Contrato', $state->attributes());
        $unchanged = ContractMapper::toModel($state);
        $this->assertSame([], $unchanged->getDirty());
        $this->assertSame($model->getAttributes(), $unchanged->getAttributes());
        $changed = ContractMapper::toModel($state->with(['numero' => '006/25']));
        $this->assertSame(['Contrato' => '006/25'], $changed->getDirty());
        $changed->save();
        $this->assertSame('006/25', $model->fresh()->numero);
    }

    public function test_column_rename_and_reverse_preserve_every_row_and_its_metadata(): void
    {
        foreach ([null, '', '005/25', " 0012 / 2026\t", '0'] as $index => $number) {
            DB::table('contracts')->insert(['Contrato' => $number, 'quality_issues' => '[{"code":"historic"}]',
                'version' => $index + 1, 'deleted_at' => $index === 2 ? '2026-10-22 12:00:00' : null]);
        }
        $before = DB::table('contracts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration = require database_path('migrations/2026_10_22_000002_rename_contract_number_column.php');
        $migration->down();
        try {
            $this->assertTrue(Schema::hasColumn('contracts', 'numero'));
            $this->assertSame(array_column($before, 'Contrato'), DB::table('contracts')->orderBy('id')->pluck('numero')->all());
        } finally {
            $migration->up();
        }
        $this->assertSame($before, DB::table('contracts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }
}
