<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve existing references rather than silently mislabeling paths as PDF bytes.
        foreach (DB::table('tb_ATA')->whereNotNull('Doc_ATA')->select(['ID', 'Doc_ATA'])->cursor() as $record) {
            if (! str_starts_with($record->Doc_ATA, '%PDF-')) {
                throw new RuntimeException('Doc_ATA contém referências existentes que precisam ser convertidas para o conteúdo dos PDFs antes da alteração.');
            }
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `tb_ATA` MODIFY COLUMN `Doc_ATA` LONGBLOB NULL');
        } else {
            Schema::table('tb_ATA', fn (Blueprint $table) => $table->binary('Doc_ATA')->nullable()->change());
        }
    }

    public function down(): void
    {
        // Never truncate stored PDFs by converting their column back to text.
    }
};
