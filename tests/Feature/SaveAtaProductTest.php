<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\SaveAtaProduct;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\User;
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
        return array_replace(['version' => 1, 'manual' => true, 'ficha' => '001', 'item' => '1', 'code' => '00042',
            'unit' => 'UN', 'description' => '<Descrição>', 'brand' => 'Marca', 'quantity' => '1,2345', 'unit_price' => '6,7890', 'places' => 2], $extra);
    }

    private function save(array $extra = [], ?int $ata = null)
    {
        return $this->postJson(route('atas.products.store', ['ata' => $ata ?? $this->ata]), $this->input($extra));
    }

    public function test_product_and_ficha_are_saved_atomically_with_server_total_and_audit(): void
    {
        $this->save(['total' => '9999', 'ATA' => 'FALSA', 'PROCESSO' => 'FALSO', 'actor_id' => 999])->assertCreated()
            ->assertJsonPath('total', '8.3800')->assertJsonPath('ficha_total', '8.3800')->assertJsonPath('version', 2)
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonMissingPath('source_row');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID_ATA' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => '2025007440',
            'ITEM' => '1', 'COD_PROD' => '00042', 'FICHA' => '001', 'ADITIVO' => 0, 'ID_ADITIVO' => null, 'VL_TOTAL' => '8.3800']);
        $this->assertDatabaseHas('tb_ValorFicha_ATA', ['ATA' => '107/2025', 'Processo' => '2025007440', 'Ficha' => '001', 'VALOR' => '8.3800']);
        $this->assertDatabaseHas('activity_events', ['actor_id' => $this->actor->id, 'action' => 'ata_product_saved']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonPath('products.0.code', '00042')
            ->assertJsonPath('products.0.ficha', '001')->assertJsonPath('ficha_totals.0.ficha', '001')->assertJsonPath('total', '8.3800');
    }

    public function test_same_ficha_accumulates_without_duplicate_rows_and_other_atas_and_fichas_remain_independent(): void
    {
        $this->save()->assertCreated();
        $this->save(['version' => 2, 'item' => '2', 'places' => 4])->assertCreated()->assertJsonPath('ficha_total', '16.7610');
        $this->assertDatabaseCount('tb_ValorFicha_ATA', 1);
        $this->save(['version' => 3, 'item' => '3', 'ficha' => '002'])->assertCreated()->assertJsonPath('ficha_total', '8.3800');
        $other = $this->createAta('108/2025');
        $this->save([], $other)->assertCreated()->assertJsonPath('ficha_total', '8.3800');
        $this->assertDatabaseCount('tb_ValorFicha_ATA', 3);
    }

    public function test_stale_or_duplicate_item_cannot_double_the_ficha_value(): void
    {
        $this->save()->assertCreated();
        $this->save()->assertConflict();
        $this->save(['version' => 2])->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseHas('tb_ValorFicha_ATA', ['VALOR' => '8.3800']);
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

    public function test_required_fields_precision_and_overflow_reject_without_writes(): void
    {
        foreach (['ficha', 'item', 'code', 'unit', 'description', 'quantity', 'unit_price'] as $field) {
            $this->save([$field => ''])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['0', '-1', '1.23456', 'NaN'] as $quantity) {
            $this->save(['quantity' => $quantity])->assertUnprocessable();
        }
        $this->save(['quantity' => '99999999999999', 'unit_price' => '99999999999999'])->assertUnprocessable();
        $this->save(['places' => 5])->assertUnprocessable();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseCount('tb_ValorFicha_ATA', 0);
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

    public function test_audit_failure_rolls_back_product_ficha_and_version(): void
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
        $this->assertDatabaseCount('tb_ValorFicha_ATA', 0);
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

    public function test_ambiguous_ficha_identity_and_ficha_overflow_do_not_create_products(): void
    {
        DB::table('tb_ValorFicha_ATA')->insert(['ATA' => '107/2025', 'Processo' => '2025007440', 'Ficha' => '001', 'VALOR' => '99999999999999.9999']);
        $this->save()->assertUnprocessable();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $other = $this->createAta('108/2025');
        DB::table('tb_ATA')->where('ID', $other)->update(['ATA' => '107/2025', 'CNPJ_FUNDO' => '11222333000181']);
        $this->save()->assertConflict();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
    }
}
