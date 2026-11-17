<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\SaveAtaProduct;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SaveAtaProductTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Admin', 'username' => 'ATA-SAVE-PRODUCT', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->ata = $this->createAta('107/2025');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function createAta(string $number): int
    {
        return DB::table('tb_ATA')->insertGetId(['ATA' => $number, 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão', 'N_LICIT' => '12',
            'fund_id' => $this->fund, 'CNPJ_FUNDO' => '04252011000110', 'source_files' => '[]', 'quality_issues' => '[]',
            'import_hash' => str_repeat('a', 64), 'version' => 1], 'ID');
    }

    private function input(array $extra = []): array
    {
        return array_replace(['version' => 1, 'manual' => true, 'item' => '1', 'code' => '00042',
            'unit' => 'UN', 'description' => '<Descrição>', 'brand' => 'Marca', 'quantity' => '1,2345', 'unit_price' => '6,7890', 'places' => 2], $extra);
    }

    private function save(array $extra = [], ?int $ata = null)
    {
        return $this->postJson(route('atas.products.store', ['ata' => $ata ?? $this->ata]), $this->input($extra));
    }

    public function test_ata_products_panel_has_no_ficha_fields_lists_or_actions(): void
    {
        $this->get(route('atas.create'))->assertOk()
            ->assertSee('data-ata-product-save-url', false)->assertSee('data-contract-product-list', false)
            ->assertDontSee('data-product-budget', false)->assertDontSee('data-products-new-ficha', false)
            ->assertDontSee('data-product-contract-total', false)->assertDontSee('<th>Ficha</th>', false);
    }

    public function test_product_is_saved_without_ficha_with_server_total_and_audit(): void
    {
        $this->save(['total' => '9999', 'ATA' => 'FALSA', 'PROCESSO' => 'FALSO', 'actor_id' => 999])->assertCreated()
            ->assertJsonPath('total', '8.3800')->assertJsonPath('ficha_total', null)->assertJsonPath('version', 2)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('source_row');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => '2025007440',
            'ITEM' => '1', 'COD_PROD' => '00042', 'FICHA' => null, 'ADITIVO' => 0, 'ID_ADITIVO' => null, 'VL_TOTAL' => '8.3800']);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => 'ata_product_saved']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonPath('products.0.code', '00042')
            ->assertJsonPath('products.0.ficha', null)->assertJsonPath('ficha_totals', [])->assertJsonPath('total', '8.3800');
    }

    public function test_products_and_ata_totals_update_without_writing_archived_ficha_values(): void
    {
        $this->save()->assertCreated();
        $this->save(['version' => 2, 'item' => '2', 'places' => 4])->assertCreated()->assertJsonPath('total', '8.3810');
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonCount(2, 'products')
            ->assertJsonPath('total', '16.7610')->assertJsonPath('ficha_totals', []);
        $other = $this->createAta('108/2025');
        $this->save([], $other)->assertCreated();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 3);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
    }

    public function test_stale_or_duplicate_item_cannot_create_duplicate_products(): void
    {
        $this->save()->assertCreated();
        $this->save()->assertConflict();
        $this->save(['version' => 2])->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
    }

    public function test_nonmanual_product_uses_catalog_fields_and_server_availability(): void
    {
        DB::table('tb_Produtos')->insert(['item' => '1', 'Processo' => '2025007440', 'Cod_Prod' => '123',
            'Discriminacao' => 'Catálogo', 'Marca' => 'Original', 'Unidade' => 'CX', 'QTD' => '2,0001', 'VL_Unitario' => '10,1234']);
        $this->save(['manual' => false, 'quantity' => '2,0002'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->save(['manual' => false, 'quantity' => '2,0001', 'unit_price' => '999', 'code' => 'FAKE', 'description' => 'FAKE'])->assertCreated()
            ->assertJsonPath('total', '20.2400');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['COD_PROD' => '123', 'DISCRIMINACAO' => 'Catálogo', 'UND' => 'CX', 'VL_UNIT' => '10.1234']);
    }

    public function test_obsolete_ficha_input_cannot_authorize_duplicate_items_or_write_ficha_values(): void
    {
        $this->save(['ficha' => '001'])->assertCreated();
        $this->save(['version' => 2, 'item' => ' 1 ', 'ficha' => '002'])->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ITEM' => '1', 'FICHA' => null]);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
    }

    public function test_products_without_ficha_still_share_the_item_process_limit_across_atas(): void
    {
        DB::table('tb_Produtos')->insert(['item' => '1', 'Processo' => '2025007440', 'Discriminacao' => 'Cat?logo',
            'Unidade' => 'UN', 'QTD' => '3.0001', 'VL_Unitario' => '1']);
        $this->save(['manual' => false, 'quantity' => '2'])->assertCreated();
        $other = $this->createAta('108/2025');
        $this->save(['manual' => false, 'quantity' => '1.0002'], $other)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->save(['manual' => false, 'quantity' => '1.0001'], $other)->assertCreated();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 2);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
        $this->getJson(route('atas.products.item-lookup', ['ata' => $this->ata, 'item' => '1']))->assertOk()
            ->assertJsonPath('product.remaining', '0.0000');
    }

    public function test_database_constraint_rejects_duplicate_ficha_and_keeps_legacy_null_ficha_unique(): void
    {
        $this->save()->assertCreated();
        $product = (array) DB::table('tb_PRODUTOS_ATA')->first();
        unset($product['ID'], $product['ficha_identity']);
        $this->assertThrows(fn () => DB::table('tb_PRODUTOS_ATA')->insert($product), UniqueConstraintViolationException::class);
        $product['FICHA'] = 'historica';
        DB::table('tb_PRODUTOS_ATA')->insert($product);
        $this->assertThrows(fn () => DB::table('tb_PRODUTOS_ATA')->insert($product), UniqueConstraintViolationException::class);
        $migration = require database_path('migrations/2026_11_15_000001_scope_ata_product_uniqueness_to_ficha.php');
        $migration->up();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 2);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
    }

    public function test_deleted_original_item_can_be_restored_with_audited_previous_snapshot_without_touching_amendment(): void
    {
        $base = ['ID_ATA' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão',
            'N_LICIT' => '12', 'ITEM' => '1', 'DISCRIMINACAO' => 'Histórico', 'UND' => 'UN', 'QTD' => '1', 'VL_UNIT' => '1', 'VL_TOTAL' => '1', 'source_row' => '{}'];
        $old = DB::table('tb_PRODUTOS_ATA')->insertGetId($base + ['deleted_at' => now()], 'ID');
        DB::table('tb_PRODUTOS_ATA')->insert($base + ['ADITIVO' => 1]);
        $this->save()->assertCreated()->assertJsonPath('id', $old);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 2);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $old, 'deleted_at' => null, 'VL_TOTAL' => '8.3800']);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ADITIVO' => 1, 'DISCRIMINACAO' => 'Histórico', 'VL_TOTAL' => '1']);
        $event = ActivityEvent::where('action', 'ata_product_saved')->firstOrFail();
        $this->assertSame('Histórico', $event->metadata['before']['DISCRIMINACAO']);
    }

    public function test_save_rechecks_remaining_across_atas_and_manual_mode_cannot_bypass_catalog_limit(): void
    {
        DB::table('tb_Produtos')->insert(['item' => '1', 'Processo' => '2025007440', 'Discriminacao' => 'Catálogo',
            'Unidade' => 'UN', 'QTD' => '10.0001', 'VL_Unitario' => '1']);
        $other = $this->createAta('108/2025');
        $row = ['ID_ATA' => $other, 'ATA' => '108/2025', 'PROCESSO' => '2025007440', 'ITEM' => '1',
            'LICITACAO' => 'Pregão', 'N_LICIT' => '12', 'DISCRIMINACAO' => 'Histórico', 'UND' => 'UN',
            'QTD' => '7.0000', 'VL_UNIT' => '1', 'VL_TOTAL' => '7', 'source_row' => '{}'];
        DB::table('tb_PRODUTOS_ATA')->insert($row);
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($row, ['ADITIVO' => 1, 'QTD' => '100', 'deleted_at' => now()]));
        foreach ([false, true] as $manual) {
            $this->save(['manual' => $manual, 'quantity' => '3.0002'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
        $this->save(['manual' => false, 'quantity' => '3.0001'])->assertCreated();
        $third = $this->createAta('109/2025');
        foreach ([false, true] as $manual) {
            $this->save(['manual' => $manual, 'quantity' => '0.0001'], $third)->assertUnprocessable()
                ->assertJsonValidationErrors('quantity')->assertJsonPath('errors.quantity.0', 'Não é possível fazer novo lançamento: este produto já atingiu o limite para esta ATA.');
        }
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $third, 'version' => 1]);
        $this->assertDatabaseHas('tb_Produtos', ['item' => '1', 'QTD' => '10.0001']);
    }

    public function test_required_fields_precision_and_overflow_reject_without_writes(): void
    {
        foreach (['item', 'unit', 'description', 'quantity', 'unit_price'] as $field) {
            $this->save([$field => ''])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['0', '-1', '1.23456', 'NaN'] as $quantity) {
            $this->save(['quantity' => $quantity])->assertUnprocessable();
        }
        $this->save(['quantity' => '99999999999999', 'unit_price' => '99999999999999'])->assertUnprocessable();
        $this->save(['places' => 5])->assertUnprocessable();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
    }

    public function test_code_and_brand_are_optional_for_manual_and_catalog_products(): void
    {
        foreach (['', null] as $index => $value) {
            $this->save(['version' => $index + 1, 'item' => (string) ($index + 1), 'code' => $value, 'brand' => $value])->assertCreated();
        }
        $input = $this->input(['version' => 3, 'item' => '3']);
        unset($input['code'], $input['brand']);
        $this->postJson(route('atas.products.store', ['ata' => $this->ata]), $input)->assertCreated();
        DB::table('tb_Produtos')->insert(['item' => '4', 'Processo' => '2025007440', 'Cod_Prod' => null,
            'Discriminacao' => 'Catálogo sem código ou marca', 'Marca' => null, 'Unidade' => 'UN', 'QTD' => '2', 'VL_Unitario' => '10']);
        $this->save(['version' => 4, 'manual' => false, 'item' => '4', 'code' => '', 'brand' => ''])->assertCreated();
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'ITEM' => '3', 'COD_PROD' => null, 'MARCA' => null]);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'ITEM' => '4', 'MARCA' => null, 'VL_UNIT' => '10.0000']);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 4);
        $this->assertDatabaseCount('activity_events', 4);
    }

    public function test_authorization_is_checked_inside_transaction_and_deleted_ata_is_rejected(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['is_active' => false]);
        try {
            app(SaveAtaProduct::class)->save($this->actor, $this->ata, $this->input(), $this->fund);
            $this->fail('Inactive actor must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        DB::table('users')->where('id', $this->actor->id)->update(['is_active' => true]);
        $this->actingAs($this->actor->fresh());
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => now()]);
        $this->save()->assertNotFound();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
    }

    public function test_audit_failure_rolls_back_product_and_version(): void
    {
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(SaveAtaProduct::class)->save($this->actor, $this->ata, $this->input(), $this->fund);
            $this->fail('Audit failure must abort persistence.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1]);
    }

    public function test_scoped_user_cannot_write_another_fund_or_use_a_stale_session(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'save-ata-products', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $this->actor = $this->actor->fresh();
        $this->actingAs($this->actor);
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $other]);
        $this->save(['fund_id' => $this->fund, 'user_type' => 'Administrador'])->assertForbidden();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $this->fund]);
        $this->save()->assertCreated();
        DB::table('users')->where('id', $this->actor->id)->update(['session_version' => $this->actor->session_version + 1]);
        $this->assertThrows(fn () => app(SaveAtaProduct::class)->save($this->actor, $this->ata, $this->input(['version' => 2, 'item' => '2']), $this->fund), HttpException::class);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
    }

    public function test_archived_ficha_values_are_preserved_and_ambiguous_ata_identity_is_rejected(): void
    {
        DB::table('tb_ValorFicha_ATA_historico')->insert(['ATA' => '107/2025', 'Processo' => '2025007440', 'Ficha' => '001', 'VALOR' => '99999999999999.9999']);
        $before = (array) DB::table('tb_ValorFicha_ATA_historico')->sole();
        $this->save()->assertCreated();
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 1);
        $this->assertSame($before, (array) DB::table('tb_ValorFicha_ATA_historico')->sole());
        $other = $this->createAta('108/2025');
        DB::table('tb_ATA')->where('ID', $other)->update(['ATA' => '107/2025', 'CNPJ_FUNDO' => '11222333000181']);
        $this->save(['version' => 2, 'item' => '2'])->assertConflict();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
    }
}
