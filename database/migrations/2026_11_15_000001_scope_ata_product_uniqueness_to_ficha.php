<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep legacy rows without FICHA unique as well as native ficha entries.
        if (! Schema::hasColumn('tb_PRODUTOS_ATA', 'ficha_identity')) {
            Schema::table('tb_PRODUTOS_ATA', fn (Blueprint $table) => $table->string('ficha_identity', 64)->storedAs("COALESCE(FICHA, '')"));
        }
        // Install the replacement first: ID_ATA's foreign key needs an index.
        if (! Schema::hasIndex('tb_PRODUTOS_ATA', 'ata_product_ficha_item_unique')) {
            Schema::table('tb_PRODUTOS_ATA', fn (Blueprint $table) => $table->unique(['ID_ATA', 'ADITIVO', 'ITEM', 'ficha_identity'], 'ata_product_ficha_item_unique'));
        }
        if (Schema::hasIndex('tb_PRODUTOS_ATA', 'ata_product_term_item_unique')) {
            Schema::table('tb_PRODUTOS_ATA', fn (Blueprint $table) => $table->dropUnique('ata_product_term_item_unique'));
        }
    }

    public function down(): void
    {
        // Preserve all product/ficha history, including items split across fichas.
    }
};
