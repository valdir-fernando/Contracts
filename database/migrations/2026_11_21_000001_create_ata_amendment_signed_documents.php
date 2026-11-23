<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $name = 'ata_amendment_signed_documents';
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('ata_id')->references('ID')->on('tb_ATA');
                $table->foreignId('amendment_id')->references('ID')->on('tb_ADT_ATA');
                $table->binary('original_pdf');
                $table->binary('redacted_pdf')->nullable();
                $table->char('original_sha256', 64);
                $table->char('redacted_sha256', 64)->nullable();
                $table->string('removed_copy', 8)->nullable();
                $table->softDeletes();
            });
        }
        // MySQL can retain the table if index creation was interrupted.
        if (! Schema::hasIndex($name, 'ata_amendment_signed_active_idx')) {
            Schema::table($name, fn (Blueprint $table) => $table->index(['ata_id', 'amendment_id', 'deleted_at'], 'ata_amendment_signed_active_idx'));
        }
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ata_amendment_signed_documents MODIFY original_pdf LONGBLOB NOT NULL, MODIFY redacted_pdf LONGBLOB NULL');
        }
    }

    public function down(): void
    {
        // Preserve filed PDFs and the history of their prior copies.
    }
};
