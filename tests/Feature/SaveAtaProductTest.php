<?php

namespace Tests\Feature;

use App\Domain\Shared\DecimalAmount;
use App\Infrastructure\Atas\SaveAtaProduct;
use App\Models\AccessScope;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
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
        $response = $this->get(route('atas.create'))->assertOk()
            ->assertSee('data-ata-product-save-url', false)->assertSee('data-contract-product-list', false)
            ->assertSee('<th aria-label="Selecionar produtos para exclusão"></th>', false)
            ->assertDontSee('data-product-budget', false)->assertDontSee('data-products-new-ficha', false)
            ->assertDontSee('data-product-contract-total', false)->assertDontSee('<th>Ficha</th>', false);
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $header = (new \DOMXPath($document))->query('//tbody[@data-contract-product-list]/preceding-sibling::thead/tr/th[last()]')->item(0);
        $this->assertSame('', trim($header->textContent));
    }

    public function test_product_list_header_remains_named_for_contracts(): void
    {
        $contract = new Contract(['tipo' => 'Serviços', 'fund_id' => $this->fund]);
        $contract->id = 1;
        $contract->exists = true;
        $html = Blade::render('<x-contract-products :contract="$contract" />', ['contract' => $contract]);
        $this->assertStringContainsString('<th>Ação</th>', $html);
        $this->assertStringNotContainsString('aria-label="Selecionar produtos para exclusão"', $html);
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

    public function test_repeated_item_accumulates_in_one_record_and_stale_retries_do_not_add_twice(): void
    {
        $this->save()->assertCreated();
        $this->save()->assertConflict();
        $before = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        $this->save(['version' => 2, 'item' => ' 1 '])->assertCreated()->assertJsonPath('id', $before['ID'])
            ->assertJsonPath('total', '16.7600')->assertJsonPath('version', 3);
        $this->save(['version' => 2])->assertConflict();
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $before['ID'], 'QTD' => '2.4690', 'VL_TOTAL' => '16.7600']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.quantity', '2.4690')->assertJsonPath('products.0.total', '16.7600')->assertJsonPath('total', '16.7600');
        $event = ActivityEvent::where('action', 'ata_product_saved')->orderByDesc('id')->firstOrFail();
        $this->assertEquals($before, $event->metadata['before']);
        $this->assertSame('16.7600', DecimalAmount::normalize((string) $event->metadata['after']['VL_TOTAL']));
        $this->assertDatabaseCount('activity_events', 2);
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

    public function test_obsolete_ficha_input_does_not_split_accumulated_items_or_write_ficha_values(): void
    {
        $this->save(['ficha' => '001'])->assertCreated();
        $this->save(['version' => 2, 'item' => ' 1 ', 'ficha' => '002'])->assertCreated();
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ITEM' => '1', 'FICHA' => null, 'QTD' => '2.4690', 'VL_TOTAL' => '16.7600']);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseCount('activity_events', 2);
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

    public function test_accumulation_respects_catalog_limit_and_keeps_other_atas_and_amendments_unchanged(): void
    {
        DB::table('tb_Produtos')->insert(['item' => '1', 'Processo' => '2025007440', 'Discriminacao' => 'Catálogo',
            'Unidade' => 'UN', 'QTD' => '10', 'VL_Unitario' => '1']);
        $other = $this->createAta('108/2025');
        $this->save(['manual' => false, 'quantity' => '3'], $other)->assertCreated();
        $otherBefore = (array) DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $other)->sole();
        $this->save(['manual' => false, 'quantity' => '2'])->assertCreated();
        $original = (array) DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $this->ata)->sole();
        $amendment = $original;
        unset($amendment['ID'], $amendment['ficha_identity']);
        $amendment['ADITIVO'] = 1;
        $amendment['QTD'] = '100';
        $amendmentId = DB::table('tb_PRODUTOS_ATA')->insertGetId($amendment, 'ID');
        $amendmentBefore = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $amendmentId)->sole();
        $this->save(['version' => 2, 'manual' => false, 'quantity' => '5.0001'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->save(['version' => 2, 'manual' => false, 'quantity' => '5'])->assertCreated()
            ->assertJsonPath('id', $original['ID'])->assertJsonPath('total', '7.0000');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $original['ID'], 'QTD' => '7', 'VL_TOTAL' => '7']);
        $this->getJson(route('atas.products.item-lookup', ['ata' => $this->ata, 'item' => '1']))
            ->assertOk()->assertJsonPath('product.remaining', '0.0000');
        $this->assertSame($otherBefore, (array) DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $other)->sole());
        $this->assertSame($amendmentBefore, (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $amendmentId)->sole());
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 3);
    }

    public function test_accumulation_rejects_different_unit_or_price_and_preserves_existing_snapshot(): void
    {
        $this->save()->assertCreated();
        $before = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        foreach (['unit' => 'KG', 'unit_price' => '9'] as $field => $value) {
            $this->save(['version' => 2, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($before, (array) DB::table('tb_PRODUTOS_ATA')->sole());
        }
        $this->save(['version' => 2, 'description' => 'Descrição alterada', 'code' => 'OUTRO', 'brand' => 'Outra marca'])->assertCreated();
        $after = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        foreach (['DISCRIMINACAO', 'COD_PROD', 'MARCA', 'source_row', 'created_at'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_legacy_originals_are_consolidated_with_audit_and_soft_delete(): void
    {
        $this->save()->assertCreated();
        $first = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        $legacy = $first;
        unset($legacy['ID'], $legacy['ficha_identity']);
        $legacy['FICHA'] = 'HISTÓRICA';
        $secondId = DB::table('tb_PRODUTOS_ATA')->insertGetId($legacy, 'ID');
        $before = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $secondId)->sole();
        $this->save(['version' => 2])->assertCreated()->assertJsonPath('id', $first['ID'])->assertJsonPath('total', '25.1400');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $first['ID'], 'QTD' => '3.7035', 'VL_TOTAL' => '25.1400', 'deleted_at' => null]);
        $after = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $secondId)->sole();
        $this->assertNotNull($after['deleted_at']);
        $this->assertSame($before['FICHA'], $after['FICHA']);
        $this->assertSame($before['QTD'], $after['QTD']);
        $this->assertSame($before['VL_TOTAL'], $after['VL_TOTAL']);
        $event = ActivityEvent::orderByDesc('id')->firstOrFail();
        $this->assertEquals($before, $event->metadata['consolidated_before'][0]);
        $this->assertNotNull($event->metadata['consolidated_after'][0]['deleted_at']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('total', '25.1400');
    }

    public function test_accumulation_rejects_quantity_or_total_overflow_without_changes(): void
    {
        foreach ([['99999999999999.9999', '0', '0.0001'], ['1', '99999999999999.9999', '1']] as $index => [$quantity, $price, $increment]) {
            $item = (string) ($index + 1);
            $this->save(['version' => $index + 1, 'item' => $item, 'quantity' => $quantity, 'unit_price' => $price, 'places' => 4])->assertCreated();
            $before = DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->all();
            $this->save(['version' => $index + 2, 'item' => $item, 'quantity' => $increment, 'unit_price' => $price, 'places' => 4])->assertUnprocessable();
            $this->assertEquals($before, DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->all());
        }
        $this->assertDatabaseCount('activity_events', 2);
    }

    public function test_audit_failure_rolls_back_accumulation_and_consolidation(): void
    {
        $this->save()->assertCreated();
        $legacy = (array) DB::table('tb_PRODUTOS_ATA')->sole();
        unset($legacy['ID'], $legacy['ficha_identity']);
        $legacy['FICHA'] = 'HISTÓRICA';
        DB::table('tb_PRODUTOS_ATA')->insert($legacy);
        $before = DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->all();
        Event::listen('eloquent.creating: '.ActivityEvent::class, function () {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(SaveAtaProduct::class)->save($this->actor, $this->ata, $this->input(['version' => 2]), $this->fund);
            $this->fail('Audit failure must abort accumulation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ActivityEvent::class);
        }
        $this->assertEquals($before, DB::table('tb_PRODUTOS_ATA')->orderBy('ID')->get()->all());
        $this->assertDatabaseHas('tb_ATA', ['ID' => $this->ata, 'version' => 2]);
        $this->assertDatabaseCount('activity_events', 1);
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

    public function test_active_amendments_do_not_consume_original_limit_in_manual_or_catalog_saves(): void
    {
        foreach ([false, true] as $index => $manual) {
            $item = (string) ($index + 1);
            DB::table('tb_Produtos')->insert(['item' => $item, 'Processo' => '2025007440',
                'Discriminacao' => 'Catálogo', 'Unidade' => 'UN', 'QTD' => '10', 'VL_Unitario' => '1']);
            $base = ['ID_ATA' => $this->ata, 'ATA' => '107/2025', 'PROCESSO' => '2025007440', 'ITEM' => $item,
                'LICITACAO' => 'Pregão', 'N_LICIT' => '12', 'DISCRIMINACAO' => 'Histórico', 'UND' => 'UN',
                'QTD' => '10', 'VL_UNIT' => '1', 'VL_TOTAL' => '10', 'source_row' => '{}'];
            $original = DB::table('tb_PRODUTOS_ATA')->insertGetId($base + ['deleted_at' => now()], 'ID');
            $amendment = DB::table('tb_PRODUTOS_ATA')->insertGetId($base + ['ADITIVO' => 1], 'ID');
            $before = (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $amendment)->sole();
            $this->getJson(route('atas.products.item-lookup', ['ata' => $this->ata, 'item' => $item]))
                ->assertOk()->assertJsonPath('product.remaining', '10.0000');
            if (! $manual) {
                $this->save(['version' => $index + 1, 'item' => $item, 'manual' => false, 'quantity' => '10.0001'])
                    ->assertUnprocessable()->assertJsonValidationErrors('quantity');
            }
            $this->save(['version' => $index + 1, 'item' => $item, 'manual' => $manual, 'quantity' => '10'])
                ->assertCreated()->assertJsonPath('id', $original);
            $this->getJson(route('atas.products.item-lookup', ['ata' => $this->ata, 'item' => $item]))
                ->assertOk()->assertJsonPath('product.remaining', '0.0000');
            $this->assertSame($before, (array) DB::table('tb_PRODUTOS_ATA')->where('ID', $amendment)->sole());
        }
        $this->assertDatabaseCount('activity_events', 2);
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

    public function test_catalog_save_rechecks_remaining_across_atas(): void
    {
        DB::table('tb_Produtos')->insert(['item' => '1', 'Processo' => '2025007440', 'Discriminacao' => 'Catálogo',
            'Unidade' => 'UN', 'QTD' => '10.0001', 'VL_Unitario' => '1']);
        $other = $this->createAta('108/2025');
        $row = ['ID_ATA' => $other, 'ATA' => '108/2025', 'PROCESSO' => '2025007440', 'ITEM' => '1',
            'LICITACAO' => 'Pregão', 'N_LICIT' => '12', 'DISCRIMINACAO' => 'Histórico', 'UND' => 'UN',
            'QTD' => '7.0000', 'VL_UNIT' => '1', 'VL_TOTAL' => '7', 'source_row' => '{}'];
        DB::table('tb_PRODUTOS_ATA')->insert($row);
        DB::table('tb_PRODUTOS_ATA')->insert(array_replace($row, ['ADITIVO' => 1, 'QTD' => '100', 'deleted_at' => now()]));
        $this->save(['manual' => false, 'quantity' => '3.0002'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertDatabaseCount('tb_ValorFicha_ATA_historico', 0);
        $this->save(['manual' => false, 'quantity' => '3.0001'])->assertCreated();
        $third = $this->createAta('109/2025');
        $this->save(['manual' => false, 'quantity' => '0.0001'], $third)->assertUnprocessable()
            ->assertJsonValidationErrors('quantity')->assertJsonPath('errors.quantity.0', 'A Quantidade limite é 0.0000');
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertDatabaseHas('tb_ATA', ['ID' => $third, 'version' => 1]);
        $this->assertDatabaseHas('tb_Produtos', ['item' => '1', 'QTD' => '10.0001']);
    }

    public function test_manual_saves_ignore_catalog_limit_and_accumulate_in_the_same_ata_and_process(): void
    {
        $catalogId = DB::table('tb_Produtos')->insertGetId(['item' => '1', 'Processo' => '2025007440',
            'Discriminacao' => 'Catálogo', 'Unidade' => 'UN', 'QTD' => '1', 'VL_Unitario' => '1']);
        $catalogBefore = (array) DB::table('tb_Produtos')->where('id', $catalogId)->sole();
        $other = $this->createAta('108/2025');
        $this->save(['manual' => false, 'quantity' => '1'], $other)->assertCreated();
        $otherBefore = (array) DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $other)->sole();
        $this->save(['manual' => true, 'quantity' => '20000', 'unit_price' => '2'])->assertCreated()->assertJsonPath('total', '40000.0000');
        $first = (array) DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $this->ata)->sole();
        $this->save(['version' => 2, 'manual' => true, 'quantity' => '30000', 'unit_price' => '2'])->assertCreated()
            ->assertJsonPath('id', $first['ID'])->assertJsonPath('total', '100000.0000');
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['ID' => $first['ID'], 'QTD' => '50000', 'VL_TOTAL' => '100000']);
        $this->getJson(route('atas.lookup', ['number' => '107/25']))->assertOk()->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.quantity', '50000.0000')->assertJsonPath('total', '100000.0000');
        $this->save(['version' => 3, 'manual' => false, 'quantity' => '0.0001'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertSame($catalogBefore, (array) DB::table('tb_Produtos')->where('id', $catalogId)->sole());
        $this->assertSame($otherBefore, (array) DB::table('tb_PRODUTOS_ATA')->where('ID_ATA', $other)->sole());
        $this->assertDatabaseCount('activity_events', 3);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 2);
    }

    public function test_manual_save_does_not_depend_on_invalid_or_ambiguous_catalog_quantities(): void
    {
        DB::table('tb_Produtos')->insert(['item' => '1', 'Processo' => '2025007440', 'QTD' => 'inválido']);
        $this->save(['quantity' => '2'])->assertCreated();
        DB::table('tb_Produtos')->insert(['item' => '1', 'Processo' => '2025007440', 'QTD' => '0']);
        $this->save(['version' => 2, 'quantity' => '3'])->assertCreated()->assertJsonPath('total', '33.9300');
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 1);
        $this->assertDatabaseHas('tb_PRODUTOS_ATA', ['QTD' => '5']);
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
