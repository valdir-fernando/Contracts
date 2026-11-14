<?php

namespace Tests\Feature;

use App\Infrastructure\Atas\AtaProductLookup;
use App\Models\AccessScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AtaProductLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private int $fund;

    private int $ata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->actor = User::create(['name' => 'Admin', 'username' => 'ATA-PRODUCT', 'password' => 'SyntheticPassword123', 'user_type' => 'Administrador'])->fresh();
        $this->fund = DB::table('contract_funds')->insertGetId(['name' => 'Fundo', 'name_key' => 'FUNDO', 'raw_payload' => '{}']);
        $this->ata = DB::table('tb_ATA')->insertGetId(['ATA' => '107/25', 'PROCESSO' => '000123/2025', 'LICITACAO' => 'Pregão', 'N_LICIT' => '12',
            'fund_id' => $this->fund, 'CNPJ_FUNDO' => '04252011000110', 'CNPJ_FORNECEDOR' => '04252011000110',
            'source_files' => '[]', 'quality_issues' => '[]', 'import_hash' => str_repeat('a', 64), 'version' => 1], 'ID');
        $this->actingAs($this->actor)->withSession(['fund_id' => $this->fund]);
    }

    private function product(array $extra = []): int
    {
        return DB::table('tb_Produtos')->insertGetId(array_replace(['item' => '001', 'Processo' => ' 000123/2025 ',
            'Cod_Prod' => '00042', 'Marca' => 'Marca A', 'Unidade' => 'CX', 'QTD' => '1.234,5678', 'VL_Unitario' => '0,0001',
            'Discriminacao' => '<Produto de teste>', 'CNPJ_Fornec' => '11222333000181', 'source_page' => 'FONTE-PRIVADA'], $extra));
    }

    private function lookup(array $params = [])
    {
        return $this->getJson(route('atas.products.item-lookup', ['ata' => $this->ata] + array_replace(['item' => '001'], $params)));
    }

    public function test_catalog_lists_only_saved_process_with_pagination_and_no_private_fields(): void
    {
        for ($item = 1; $item <= 51; $item++) {
            $this->product(['item' => (string) $item]);
        }
        $this->product(['Processo' => 'OUTRO', 'Discriminacao' => 'FORA-DO-PROCESSO']);
        $this->getJson(route('atas.products.catalog', ['ata' => $this->ata, 'process' => 'OUTRO']))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('process', '000123/2025')->assertJsonPath('page', 1)->assertJsonPath('last_page', 2)
            ->assertJsonCount(50, 'products')->assertJsonMissingPath('products.0.CNPJ_Fornec')
            ->assertDontSee('FONTE-PRIVADA')->assertDontSee('11222333000181')->assertDontSee('FORA-DO-PROCESSO');
        $this->getJson(route('atas.products.catalog', ['ata' => $this->ata, 'page' => 2]))->assertOk()->assertJsonCount(1, 'products');
        $this->actor->update(['user_type' => 'Usuario']);
        $this->assertThrows(fn () => app(AtaProductLookup::class)->listing($this->actor->fresh(), $this->ata, []), HttpException::class);
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_exact_item_and_saved_process_fill_explicit_fields_without_supplier_filter_or_writes(): void
    {
        $id = $this->product();
        $this->product(['Processo' => 'OUTRO PROCESSO', 'Cod_Prod' => 'OUTRO']);
        $this->product(['item' => '1', 'Cod_Prod' => 'SEM-ZEROS']);
        $this->lookup(['item' => ' 001 ', 'process' => 'OUTRO PROCESSO'])->assertOk()
            ->assertExactJson(['process' => '000123/2025', 'product' => ['id' => $id, 'item' => '001', 'code' => '00042',
                'brand' => 'Marca A', 'unit' => 'CX', 'remaining' => '1234.5678', 'unit_price' => '0.0001', 'description' => '<Produto de teste>']])
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('FONTE-PRIVADA')->assertDontSee('11222333000181');
        $this->assertDatabaseCount('activity_events', 0);
        $this->assertDatabaseCount('tb_PRODUTOS_ATA', 0);
        $this->assertDatabaseCount('tb_ValorFicha_ATA', 0);
        $this->assertDatabaseHas('tb_Produtos', ['id' => $id, 'QTD' => '1.234,5678']);
    }

    public function test_process_cannot_be_overridden_and_missing_or_ambiguous_items_do_not_return_arbitrary_product(): void
    {
        $this->product(['Processo' => 'OUTRO']);
        $this->lookup(['process' => 'OUTRO'])->assertNotFound();
        $this->product();
        $this->product(['CNPJ_Fornec' => '04252011000110']);
        $this->lookup()->assertConflict()->assertJsonMissingPath('product');
        $this->lookup(['item' => '999'])->assertNotFound();
    }

    public function test_decimal_strings_retain_four_places_large_values_and_zero_balance(): void
    {
        $id = $this->product(['QTD' => '99999999999999.9999', 'VL_Unitario' => '12345678901234.5678']);
        $this->lookup()->assertOk()->assertJsonPath('product.remaining', '99999999999999.9999')->assertJsonPath('product.unit_price', '12345678901234.5678');
        DB::table('tb_Produtos')->where('id', $id)->update(['QTD' => '0']);
        $this->lookup()->assertOk()->assertJsonPath('product.remaining', '0.0000');
        DB::table('tb_Produtos')->where('id', $id)->update(['QTD' => 'inválido']);
        $this->lookup()->assertUnprocessable()->assertJsonValidationErrors('item');
    }

    public function test_input_deleted_ata_and_scopes_are_validated_before_returning_data(): void
    {
        $this->product();
        $this->lookup(['item' => ' '])->assertUnprocessable();
        $this->lookup(['item' => str_repeat('1', 101)])->assertUnprocessable();
        $this->actor->forceFill(['user_type' => 'Usuario'])->save();
        $scope = AccessScope::create(['scope_key' => 'ATA-PRODUCT-SCOPE', 'fund_id' => $this->fund, 'fund' => 'Fundo']);
        $this->actor->scopes()->attach($scope);
        $this->actingAs($this->actor->fresh());
        $this->lookup()->assertOk();
        $otherFund = DB::table('contract_funds')->insertGetId(['name' => 'Outro', 'name_key' => 'OUTRO', 'raw_payload' => '{}']);
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $otherFund]);
        $this->lookup()->assertNotFound()->assertJsonMissingPath('product');
        DB::table('tb_ATA')->where('ID', $this->ata)->update(['fund_id' => $this->fund, 'deleted_at' => now()]);
        $this->lookup()->assertNotFound();
    }

    public function test_service_revalidates_actor_session_and_rejects_guest(): void
    {
        $expected = $this->actor->fresh();
        $this->actor->forceFill(['session_version' => 1])->save();
        try {
            app(AtaProductLookup::class)->find($expected, $this->ata, ['item' => '001']);
            $this->fail('Stale actor accepted');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        auth()->logout();
        $this->lookup()->assertUnauthorized();
    }

    public function test_process_change_during_read_is_revalidated_before_product_response(): void
    {
        $this->product();
        $armed = true;
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_contains($query->sql, '"tb_Produtos"')) {
                $armed = false;
                DB::table('tb_ATA')->where('ID', $this->ata)->update(['PROCESSO' => 'OUTRO', 'version' => 2]);
            }
        });
        $this->lookup()->assertConflict()->assertJsonMissingPath('product');
    }
}
