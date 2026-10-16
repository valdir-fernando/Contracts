<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_Produtos', function (Blueprint $table) {
            $table->unsignedBigInteger('pool_id')->nullable()->change();
            foreach (['item' => 100, 'unit' => 40, 'source_page' => 100] as $name => $length) {
                $table->string($name, $length)->default('')->change();
            }
            $table->text('description')->nullable()->change();
            foreach (['quantity', 'remaining', 'unit_price'] as $name) {
                $table->decimal($name, 18, 4)->default('0')->change();
            }
            $table->unsignedBigInteger('ID_Produto')->nullable()->unique();
            foreach (['Processo', 'Cod_Prod', 'CNPJ_Fornec', 'Discriminacao', 'Marca', 'Unidade', 'QTD', 'QTD_Acumulado', 'QTD_Autorizado', 'VL_Unitario', 'VL_Total'] as $name) {
                $table->text($name)->nullable();
            }
            // Item already exists as `item`; MySQL/SQLite column names ignore case.
        });
        DB::table('tb_Produtos')->orderBy('id')->each(function ($product) {
            $pool = $product->pool_id ? DB::table('procurement_pools')->find($product->pool_id) : null;
            DB::table('tb_Produtos')->where('id', $product->id)->update([
                'ID_Produto' => $product->id, 'Processo' => $pool?->process_number,
                'CNPJ_Fornec' => $pool?->supplier_document, 'Cod_Prod' => '', 'Marca' => '',
                'Discriminacao' => $product->description, 'Unidade' => $product->unit,
                'QTD' => (string) $product->remaining, 'QTD_Acumulado' => (string) $product->quantity,
                'VL_Unitario' => (string) $product->unit_price,
            ]);
        });
        Schema::table('tb_Produtos_Contratos', function (Blueprint $table) {
            $table->string('code', 100)->default('');
            $table->string('brand', 255)->default('');
            $table->foreignId('budget_id')->nullable()->constrained('contract_financial_records')->restrictOnDelete();
            foreach (['CNPJ', 'Contrato', 'Processo', 'FUNDO', 'Ficha', 'Cod_Prod', 'Descricao', 'Discriminacao', 'Marca', 'UND', 'QTD', 'VL_UNIT', 'TOTAL_UNIT'] as $name) {
                $table->text($name)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Catalog and contract history are retained.
    }
};
