<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DDL is not transactional in MySQL; safely resume after an interrupted install.
        if (! Schema::hasColumn('tb_PRODUTOS_ATA', 'ADITIVO')) {
            Schema::table('tb_PRODUTOS_ATA', function (Blueprint $table) {
                $table->unsignedInteger('ADITIVO')->default(0)->after('N_LICIT');
                $table->date('DATA_ADT')->nullable()->after('ADITIVO');
                $table->string('PROC_ADT', 64)->nullable()->after('DATA_ADT');
                $table->unsignedBigInteger('ID_ADITIVO')->nullable();
            });
        }
        Schema::table('tb_PRODUTOS_ATA', function (Blueprint $table) {
            // An amendment remains identifiable even while its original ata is unavailable.
            $table->unsignedBigInteger('ID_ATA')->nullable()->change();
        });
        // Install the replacement first: MySQL also uses the original index for ID_ATA's FK.
        if (! Schema::hasIndex('tb_PRODUTOS_ATA', 'ata_product_term_item_unique')) {
            Schema::table('tb_PRODUTOS_ATA', fn (Blueprint $table) => $table->unique(['ID_ATA', 'ADITIVO', 'ITEM'], 'ata_product_term_item_unique'));
        }
        if (! Schema::hasIndex('tb_PRODUTOS_ATA', 'ata_amendment_product_item_unique')) {
            Schema::table('tb_PRODUTOS_ATA', fn (Blueprint $table) => $table->unique(['ID_ADITIVO', 'ITEM'], 'ata_amendment_product_item_unique'));
        }
        if (Schema::hasIndex('tb_PRODUTOS_ATA', 'tb_produtos_ata_id_ata_item_unique')) {
            Schema::table('tb_PRODUTOS_ATA', fn (Blueprint $table) => $table->dropUnique(['ID_ATA', 'ITEM']));
        }
        $foreigns = Schema::getForeignKeys('tb_PRODUTOS_ATA');
        if (! array_filter($foreigns, fn ($foreign) => $foreign['columns'] === ['ID_ADITIVO'])) {
            Schema::table('tb_PRODUTOS_ATA', fn (Blueprint $table) => $table->foreign('ID_ADITIVO')->references('ID')->on('tb_ADITIVOS_ATA')->restrictOnDelete());
        }
        if (! Schema::hasColumn('tb_ADITIVOS_ATA', 'products_import')) {
            Schema::table('tb_ADITIVOS_ATA', fn (Blueprint $table) => $table->json('products_import')->nullable());
        }
    }

    public function down(): void
    {
        // Preserve amendment products, identification and documentary history.
    }
};
