<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['procurement_imports', 'procurement_pools', 'procurement_pool_contract', 'tb_Produtos', 'tb_Produtos_Contratos'] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException('Tabela de produtos existente: '.$name.'. Preserve os dados e concilie o schema antes de instalar este módulo.');
            }
        }
        Schema::create('procurement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('session_version');
            $table->string('process_number');
            $table->string('supplier_document', 14);
            $table->string('filename');
            $table->string('path');
            $table->string('sha256', 64);
            $table->string('status', 20)->default('queued');
            $table->json('draft')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('procurement_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->unique()->constrained('procurement_imports')->restrictOnDelete();
            $table->string('identity', 64)->unique();
            $table->string('process_number');
            $table->string('supplier_document', 14);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('procurement_pool_contract', function (Blueprint $table) {
            $table->foreignId('pool_id')->constrained('procurement_pools')->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->unique(['pool_id', 'contract_id']);
        });
        Schema::create('tb_Produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained('procurement_pools')->restrictOnDelete();
            $table->string('item', 100);
            $table->text('description');
            $table->string('unit', 40);
            $table->decimal('quantity', 18, 4);
            $table->decimal('remaining', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->string('source_page', 100);
            $table->timestamps();
            $table->unique(['pool_id', 'item']);
        });
        Schema::create('tb_Produtos_Contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained('tb_Produtos')->restrictOnDelete();
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
        });
        Schema::table('contracts', fn (Blueprint $table) => $table->boolean('items_managed')->default(false));
    }

    public function down(): void
    {
        // Historical product distributions must be retained.
    }
};
