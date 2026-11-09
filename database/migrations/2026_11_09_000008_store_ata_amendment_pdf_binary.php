<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never silently turn a previously stored path into supposed PDF bytes.
        foreach (DB::table('tb_ADT_ATA')->whereNotNull('Doc_ADT')->select(['ID', 'Doc_ADT'])->cursor() as $record) {
            if (! str_starts_with($record->Doc_ADT, '%PDF-')) {
                throw new RuntimeException('Doc_ADT contém referências existentes que precisam ser convertidas para o conteúdo dos PDFs antes da alteração.');
            }
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `tb_ADT_ATA` MODIFY COLUMN `Doc_ADT` LONGBLOB NULL');
        } else {
            Schema::table('tb_ADT_ATA', fn (Blueprint $table) => $table->binary('Doc_ADT')->nullable()->change());
        }
    }

    public function down(): void
    {
        // Do not truncate stored PDF bytes by converting the column back to text.
    }
};
