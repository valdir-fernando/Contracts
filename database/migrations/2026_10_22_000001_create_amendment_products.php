<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_Prtodutos_Aditivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('tb_Produtos')->restrictOnDelete();
            $table->string('item', 100);
            $table->text('description');
            $table->string('unit', 40);
            $table->string('process_number');
            $table->string('supplier_document', 14);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('total', 18, 4);
            $table->timestamps();
            $table->softDeletes();
            $table->string('code', 100)->default('');
            $table->string('brand')->default('');
            $table->foreignId('budget_id')->nullable()->constrained('contract_financial_records')->restrictOnDelete();
            foreach (['CNPJ', 'Contrato', 'Processo', 'FUNDO', 'Ficha', 'Cod_Prod', 'Descricao', 'Discriminacao', 'Marca', 'UND', 'QTD', 'VL_UNIT', 'TOTAL_UNIT'] as $name) {
                $table->text($name)->nullable();
            }
            $table->boolean('process_catalog')->default(false);
            $table->foreignId('legacy_record_id')->nullable()->unique()->constrained('legacy_records')->restrictOnDelete();
            $table->text('ADT')->nullable();
            $table->text('Proc_ADT')->nullable();
            $table->index(['contract_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        // Amendment history must survive rollback; no destructive reversal.
    }
};
