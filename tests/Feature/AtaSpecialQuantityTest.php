<?php

namespace Tests\Feature;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Atas\RemoveAtaSpecialQuantity;
use App\Infrastructure\Atas\UpdateAtaSpecialQuantity;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class AtaSpecialQuantityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Admin', 'username' => 'SPECIAL-QTY', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->ata = DB::table('tb_ATA')->insertGetId(['ATA' => '107/2025', 'PROCESSO' => 'PROC', 'LICITACAO' => 'Pregão', 'N_LICIT' => '17',
            'fund_id' => $this->fund, 'CNPJ_FUNDO' => '04252011000110', 'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('a', 64), 'version' => 1], 'ID');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function configure(?string $name, int $version = 1)
    {
        return $this->patchJson(route('atas.special-quantity.update', ['ata' => $this->ata]), ['version' => $version, 'special_quantity_name' => $name]);
    }

    private function save(int $version, array $extra = [])
    {
        return $this->postJson(route('atas.products.store', ['ata' => $this->ata]), array_replace([
            'version' => $version, 'manual' => true, 'item' => '2', 'unit' => 'UN', 'description' => 'Produto',
            'quantity' => '12', 'unit_price' => '100', 'places' => 2, 'special_quantity' => '2',
        ], $extra));
    }

    private function remove(int $version)
    {
        return $this->deleteJson(route('atas.special-quantity.destroy', ['ata' => $this->ata]), ['version' => $version]);
    }

    public function test_removal_clears_only_special_data_for_this_ata_and_audits_all_removed_values(): void
    {
        $this->configure('veículos')->assertOk();
        $this->save(2)->assertCreated();
        $original = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        $copy = $original;
        unset($copy['ID'], $copy['ficha_identity']);
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($copy, ['ITEM' => '3', 'ADITIVO' => 1, 'QTD_ESPECIAL' => '4.1234']));
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($copy, ['ITEM' => '4', 'deleted_at' => now(), 'QTD_ESPECIAL' => '9']));
        $otherAta = (array) DB::table('tb_ATA')->sole();
        unset($otherAta['ID']);
        $otherId = DB::table('tb_ATA')->insertGetId(array_replace($otherAta, ['ATA' => '108/2025']), 'ID');
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($copy, ['ID_ATA' => $otherId, 'ATA' => '108/2025']));
        $before = DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->map(fn ($row) => (array) $row)->all();
        $this->remove(3)->assertOk()->assertExactJson(['id' => $this->ata, 'number' => '107/2025', 'version' => 4, 'special_quantity_name' => null])
            ->assertHeader('Cache-Control', 'no-store, private');
        $after = DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->map(fn ($row) => (array) $row)->all();
        foreach ($before as $index => $row) {
            $this->assertSame((int) $row['ID_ATA'] === $this->ata ? null : $row['QTD_ESPECIAL'], $after[$index]['QTD_ESPECIAL']);
            unset($before[$index]['QTD_ESPECIAL'], $before[$index]['updated_at'], $after[$index]['QTD_ESPECIAL'], $after[$index]['updated_at']);
        }
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $otherId, 'QTD_ESPECIAL_NOME' => 'veículos']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonPath('special_quantity_name', null)
            ->assertJsonPath('products.0.special_quantity', null)->assertJsonPath('total', '1200.0000');
        $event = ActivityEvent::where('action', 'ata_special_quantity_removed')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertSame('veículos', $event->metadata['before']['QTD_ESPECIAL_NOME']);
        $this->assertCount(3, $event->metadata['before']['products']);
        $this->assertNull($event->metadata['after']['products'][0]['QTD_ESPECIAL']);
        $this->remove(4)->assertOk()->assertJsonPath('version', 4);
        $this->assertSame(1, ActivityEvent::where('action', 'ata_special_quantity_removed')->count());
        $this->configure('outra', 4)->assertOk();
        $this->assertSame(0, DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $this->ata)->whereNotNull('QTD_ESPECIAL')->count());
    }

    public function test_removal_rejects_missing_stale_versions_and_deleted_atas_without_clearing_values(): void
    {
        $this->configure('veículos')->assertOk();
        $this->save(2)->assertCreated();
        $this->deleteJson(route('atas.special-quantity.destroy', ['ata' => $this->ata]), [])->assertUnprocessable();
        $this->remove(2)->assertConflict()->assertJsonMissingPath('special_quantity_name');
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => now()]);
        $this->remove(3)->assertNotFound();
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'QTD_ESPECIAL' => '2']);
        $this->assertSame(0, ActivityEvent::where('action', 'ata_special_quantity_removed')->count());
    }

    public function test_failed_removal_audit_rolls_back_name_values_and_version(): void
    {
        $this->configure('veículos')->assertOk();
        $this->save(2)->assertCreated();
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(RemoveAtaSpecialQuantity::class)->remove($this->actor, $this->ata, ['version' => 3], $this->fund);
            $this->fail('Audit failure must abort removal.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'QTD_ESPECIAL_NOME' => 'veículos', 'version' => 3]);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'QTD_ESPECIAL' => '2']);
        $this->assertSame(0, ActivityEvent::where('action', 'ata_special_quantity_removed')->count());
    }

    public function test_empty_ok_does_not_change_configuration_version_or_audit(): void
    {
        foreach ([null, '', '   '] as $name) {
            $this->configure($name)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('special_quantity_name', null);
        }
        $this->assertDatabaseCount('activity_events', 0);
        $this->configure(' veículos ')->assertOk()->assertJsonPath('version', 2)->assertJsonPath('special_quantity_name', 'veículos');
        $this->configure('', 2)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('special_quantity_name', 'veículos');
        $this->configure('veículos', 2)->assertOk()->assertJsonPath('version', 2);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'QTD_ESPECIAL_NOME' => 'veículos', 'version' => 2]);
    }

    public function test_special_values_persist_accumulate_and_reload_without_changing_financial_calculation(): void
    {
        $this->configure('veículos')->assertOk();
        $this->save(2)->assertCreated()->assertJsonPath('total', '1200.0000');
        $this->save(3, ['special_quantity' => '0,1250'])->assertCreated()->assertJsonPath('total', '2400.0000');
        $this->save(4, ['special_quantity' => null])->assertCreated();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('special_quantity_name', 'veículos')->assertJsonPath('products.0.special_quantity', '2.1250')
            ->assertJsonPath('products.0.quantity', '36.0000')->assertJsonPath('total', '3600.0000');
        $this->configure('unidades especiais', 5)->assertOk();
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'QTD_ESPECIAL' => '2.1250']);
        $event = ActivityEvent::where('action', 'ata_product_saved')->orderByDesc('id')->firstOrFail();
        $this->assertSame('2.1250', DecimalAmount::normalize((string) $event->metadata['after']['QTD_ESPECIAL']));
    }

    public function test_values_require_configuration_and_validation_and_ordinary_atas_stay_unconfigured(): void
    {
        $this->save(1)->assertUnprocessable()->assertJsonValidationErrors('special_quantity');
        $this->save(1, ['special_quantity' => null])->assertCreated();
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()
            ->assertJsonPath('special_quantity_name', null)->assertJsonPath('products.0.special_quantity', null);
        $this->configure('veículos', 2)->assertOk();
        foreach (['-1', 'abc', '100000000000000'] as $value) {
            $this->save(3, ['special_quantity' => $value])->assertUnprocessable()->assertJsonValidationErrors('special_quantity');
        }
        $this->configure(str_repeat('a', 61), 3)->assertUnprocessable();
        $this->configure('outro', 1)->assertConflict()->assertJsonMissingPath('special_quantity_name');
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 3]);
    }

    public function test_configuration_requires_permission_and_current_session(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'SPECIAL-QTY-FUND', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $other]);
        $this->actingAs($this->actor->fresh());
        $this->configure('veículos')->assertForbidden();
        $this->remove(1)->assertForbidden();
        auth()->logout();
        $this->configure('veículos')->assertUnauthorized();
        $this->remove(1)->assertUnauthorized();
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_failed_audit_rolls_back_configuration(): void
    {
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(UpdateAtaSpecialQuantity::class)->update($this->actor, $this->ata, ['version' => 1, 'special_quantity_name' => 'veículos'], $this->fund);
            $this->fail('Audit failure must abort update.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'QTD_ESPECIAL_NOME' => null, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }
}
