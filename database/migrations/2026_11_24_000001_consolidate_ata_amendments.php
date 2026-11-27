<?php

use App\Infrastructure\Atas\ConsolidateAtaAmendments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate additive DDL from the transactional data conversion (MySQL).
        $columns = [
            'ID_ATA' => fn (Blueprint $t) => $t->unsignedBigInteger('ID_ATA')->nullable()->index(),
            'imported_id' => fn (Blueprint $t) => $t->unsignedBigInteger('imported_id')->nullable()->unique(),
            'N_LICIT' => fn (Blueprint $t) => $t->string('N_LICIT', 32)->nullable(),
            'CNPJ_FORNECEDOR' => fn (Blueprint $t) => $t->string('CNPJ_FORNECEDOR', 18)->nullable(),
            'source_path' => fn (Blueprint $t) => $t->string('source_path', 512)->nullable()->unique(),
            'source_sha256' => fn (Blueprint $t) => $t->char('source_sha256', 64)->nullable(),
            'source_size' => fn (Blueprint $t) => $t->unsignedBigInteger('source_size')->nullable(),
            'extraction' => fn (Blueprint $t) => $t->string('extraction', 32)->nullable(),
            'link_status' => fn (Blueprint $t) => $t->string('link_status', 32)->nullable(),
            'issues' => fn (Blueprint $t) => $t->json('issues')->nullable(),
            'products_import' => fn (Blueprint $t) => $t->json('products_import')->nullable(),
            'import_snapshot' => fn (Blueprint $t) => $t->json('import_snapshot')->nullable(),
            'edited_in_panel_at' => fn (Blueprint $t) => $t->timestamp('edited_in_panel_at')->nullable(),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('tb_ADT_ATA', $name)) {
                Schema::table('tb_ADT_ATA', $add);
            }
        }
        if (! Schema::hasColumn('tb_PRODUTOS_ATA', 'amendment_id')) {
            Schema::table('tb_PRODUTOS_ATA', function (Blueprint $table) {
                $table->unsignedBigInteger('amendment_id')->nullable()->index();
                $table->foreign('amendment_id', 'ata_product_amendment_fk')->references('ID')->on('tb_ADT_ATA')->restrictOnDelete();
            });
        }
        (new ConsolidateAtaAmendments)->run();
    }

    public function down(): void
    {
        // Preserve unified records, original import tables, IDs and documentary history.
    }
};
