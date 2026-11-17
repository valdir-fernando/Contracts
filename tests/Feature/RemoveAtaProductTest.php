<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\RemoveAtaProduct;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RemoveAtaProductTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $ata;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Admin', 'username' => 'REMOVE-ATA-PRODUCT', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->ata = DB::table('tb_ATA')->insertGetId(['ATA' => '107/2025', 'PROCESSO' => '2025007440', 'LICITACAO' => 'Pregão',
            'N_LICIT' => '12', 'fund_id' => $this->fund, 'CNPJ_FUNDO' => '04252011000110', 'source_files' => '[]',
            'quality_issues' => '[]', 'import_hash' => str_repeat('a', 64), 'version' => 1], 'ID');
        $this->product = DB::table('tb_PRODUTOS_ATA')->insertGetId(['ID_ATA' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => '2025007440',
            'LICITACAO' => 'Pregão', 'N_LICIT' => '12', 'ITEM' => '2', 'DISCRIMINACAO' => 'Original', 'UND' => 'UN',
            'QTD' => '10', 'VL_UNIT' => '2', 'VL_TOTAL' => '20', 'source_row' => '{}'], 'ID');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function remove(array $extra = [])
    {
        return $this->deleteJson(route('atas.products.destroy', ['ata' => $this->ata]),
            array_replace(['item' => '2', 'quantity' => '4', 'version' => 1], $extra));
    }

    private function anotherProduct(array $extra = []): int
    {
        $row = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $this->product)->sole();
        unset($row['ID'], $row['ficha_identity']);

        return DB::table('tb_PRODUTOS_ATA')->insertGetId(array_replace($row, ['ITEM' => '3'], $extra), 'ID');
    }

    public function test_selected_rows_are_fully_removed_together_with_one_version_and_atomic_audit(): void
    {
        $second = $this->anotherProduct();
        $amendment = $this->anotherProduct(['ADITIVO' => 1, 'ITEM' => '4']);
        $before = DB::table('tb_PRODUTOS_ATA')->whereIn('ID', [$this->product, $second])->orderBy('ID')->get()->all();
        $this->remove(['product_ids' => [$second, $this->product], 'quantity' => '0.0001'])->assertOk()
            ->assertJsonPath('removed_count', 2)->assertJsonPath('version', 2)->assertJsonPath('deleted', true);
        foreach ($before as $product) {
            $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $product->ID, 'QTD' => $product->QTD, 'VL_TOTAL' => $product->VL_TOTAL]);
            $this->assertNotNull(DB::table('tb_PRODUTOS_ATA')->where('ID', $product->ID)->value('deleted_at'));
        }
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $amendment, 'deleted_at' => null]);
        $event = ActivityEvent::where('action', 'ata_products_removed')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertCount(2, $event->metadata['before']);
        $this->assertCount(2, $event->metadata['after']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonCount(0, 'products')->assertJsonPath('total', '0.0000');
        $this->remove(['product_ids' => [$this->product]])->assertConflict();
    }

    public function test_invalid_selected_rows_abort_the_whole_batch_without_falling_back_to_fields(): void
    {
        $invalids = [$this->anotherProduct(['ADITIVO' => 1]), $this->anotherProduct(['PROCESSO' => 'OTHER', 'ITEM' => '5']),
            $this->anotherProduct(['deleted_at' => now(), 'ITEM' => '6']), $this->anotherProduct(['ID_ATA' => null, 'ITEM' => '7'])];
        foreach ($invalids as $id) {
            $this->remove(['product_ids' => [$this->product, $id]])->assertUnprocessable();
        }
        foreach ([[], [$this->product, $this->product], [0], ['bad']] as $ids) {
            $this->remove(['product_ids' => $ids])->assertUnprocessable();
        }
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $this->product, 'deleted_at' => null]);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_one_selected_row_is_fully_removed_even_without_form_fields(): void
    {
        $this->deleteJson(route('atas.products.destroy', ['ata' => $this->ata]), ['version' => 1, 'product_ids' => [$this->product]])
            ->assertOk()->assertJsonPath('removed_count', 1);
        $this->assertNotNull(DB::table('tb_PRODUTOS_ATA')->where('ID', $this->product)->value('deleted_at'));
    }

    public function test_batch_audit_failure_rolls_back_all_selected_products_and_version(): void
    {
        $second = $this->anotherProduct();
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(RemoveAtaProduct::class)->remove($this->actor, $this->ata, ['version' => 1, 'product_ids' => [$this->product, $second]], $this->fund);
            $this->fail('Audit failure must abort batch removal.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertSame(2, DB::table('tb_PRODUTOS_ATA')->whereNull('deleted_at')->count());
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_partial_removal_updates_quantity_value_list_and_audit_without_changing_other_fields(): void
    {
        $before = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $this->product)->sole();
        $this->remove(['quantity' => '4', 'unit_price' => '9999', 'PROCESSO' => 'FALSO', 'actor_id' => 999])->assertOk()
            ->assertJsonPath('deleted', false)->assertJsonPath('quantity', '6.0000')->assertJsonPath('total', '12.0000')
            ->assertJsonPath('version', 2)->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('source_row');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $this->product, 'QTD' => '6', 'VL_TOTAL' => '12', 'deleted_at' => null]);
        $after = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $this->product)->sole();
        foreach (['VL_UNIT', 'source_row', 'DISCRIMINACAO', 'FICHA'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $event = ActivityEvent::where('action', 'ata_product_removed')->sole();
        $this->assertSame($this->actor->id, $event->actor_id);
        $this->assertEquals($before, $event->metadata['before']);
        $this->assertSame('4.0000', $event->metadata['quantity_removed']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.quantity', '6.0000')->assertJsonPath('total', '12.0000');
    }

    public function test_equal_quantity_soft_deletes_original_preserving_history_and_amendment(): void
    {
        $amendment = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $this->product)->sole();
        unset($amendment['ID'], $amendment['ficha_identity']);
        $amendment['ADITIVO'] = 1;
        $amendmentId = DB::table('tb_PRODUTOS_ATA')->insertGetId($amendment, 'ID');
        $before = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $amendmentId)->sole();
        $this->remove(['quantity' => '10'])->assertOk()->assertJsonPath('deleted', true)->assertJsonPath('total', '0.0000');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $this->product, 'QTD' => '10', 'VL_TOTAL' => '20']);
        $this->assertNotNull(DB::table('tb_PRODUTOS_ATA')->where('ID', $this->product)->value('deleted_at'));
        $this->assertSame($before, (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $amendmentId)->sole());
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonCount(0, 'products')->assertJsonPath('total', '0.0000');
        $this->remove(['quantity' => '10'])->assertConflict();
        $this->remove(['quantity' => '10', 'version' => 2])->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->assertDatabaseCount('activity_events', 1);
    }

    public function test_excess_quantity_reports_limit_without_changes_and_stale_retry_does_not_remove_twice(): void
    {
        $before = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        $this->remove(['quantity' => '10.0001'])->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', 'A quantidade máxima que pode ser excluída deste Item é 10,0000.');
        $this->assertSame($before, (array) DB::table('tb_PRODUTOS_ATA')->sole());
        $this->assertDatabaseCount('activity_events', 0);
        $this->remove()->assertOk();
        $this->remove()->assertConflict()->assertJsonMissingPath('quantity');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $this->product, 'QTD' => '6']);
    }

    public function test_invalid_quantity_and_items_from_other_processes_are_rejected(): void
    {
        foreach (['', '0', '-1', '1.23456', 'NaN'] as $quantity) {
            $this->remove(['quantity' => $quantity])->assertUnprocessable();
        }
        $this->remove(['item' => '02'])->assertUnprocessable()->assertJsonValidationErrors('item');
        DB::table('tb_PRODUTOS_ATA')->where('ID', $this->product)->update(['PROCESSO' => 'OUTRO']);
        $this->remove()->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_removal_does_not_depend_on_catalog_balance_and_releases_original_quantity(): void
    {
        DB::table('tb_Produtos')->insert(['item' => '2', 'Processo' => '2025007440', 'QTD' => '10', 'VL_Unitario' => '2']);
        $before = (array) DB::table('tb_Produtos')->sole();
        $this->getJson(route('atas.products.item-lookup', ['ata' => $this->ata, 'item' => '2']))->assertOk()->assertJsonPath('product.remaining', '0.0000');
        $this->remove()->assertOk();
        $this->getJson(route('atas.products.item-lookup', ['ata' => $this->ata, 'item' => '2']))->assertOk()->assertJsonPath('product.remaining', '4.0000');
        $this->assertSame($before, (array) DB::table('tb_Produtos')->sole());
    }

    public function test_partial_total_is_proportional_to_stored_total_with_exact_decimals(): void
    {
        DB::table('tb_PRODUTOS_ATA')->where('ID', $this->product)->update(['QTD' => '3', 'VL_TOTAL' => '1']);
        $this->remove(['quantity' => '1'])->assertOk()->assertJsonPath('quantity', '2.0000')->assertJsonPath('total', '0.6666');
    }

    public function test_guests_inactive_users_and_users_without_fund_permission_cannot_remove(): void
    {
        auth()->logout();
        $this->remove()->assertUnauthorized();
        $this->actingAs($this->actor);
        DB::table('users')->where('id', $this->actor->id)->update(['is_active' => false]);
        $this->remove()->assertForbidden();
        DB::table('users')->where('id', $this->actor->id)->update(['is_active' => true, 'user_type' => 'Usuario']);
        $this->actingAs($this->actor->fresh());
        // The HTTP middleware ends a session whose selected fund is no longer allowed.
        $this->remove(['fund_id' => $this->fund, 'user_type' => 'Administrador'])->assertUnauthorized();
        try {
            app(RemoveAtaProduct::class)->remove($this->actor->fresh(), $this->ata, ['version' => 1, 'item' => '2', 'quantity' => '1'], $this->fund);
            $this->fail('The adapter must reauthorize the current actor.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $this->product, 'QTD' => '10']);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_authorized_fund_scope_cannot_remove_a_product_from_another_ata_fund(): void
    {
        DB::table('users')->where('id', $this->actor->id)->update(['user_type' => 'Usuario']);
        $scope = AccessScope::create(['scope_key' => 'REMOVE-ATA', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh())->withSession(['fund_id' => $this->fund]);
        $other = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $other]);
        $this->remove(['fund_id' => $this->fund])->assertForbidden();
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $this->product, 'QTD' => '10']);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_deleted_ata_cannot_be_changed_and_audit_failure_rolls_back_removal(): void
    {
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => now()]);
        $this->remove()->assertNotFound();
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['deleted_at' => null]);
        $before = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            app(RemoveAtaProduct::class)->remove($this->actor, $this->ata, ['version' => 1, 'item' => '2', 'quantity' => '10'], $this->fund);
            $this->fail('Audit failure must roll back deletion.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertSame($before, (array) DB::table('tb_PRODUTOS_ATA')->sole());
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 1]);
        $this->assertDatabaseCount('activity_events', 0);
    }
}
