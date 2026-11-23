<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ata_signed_document_copies', function (Blueprint $table) {
            $table->unsignedBigInteger('ata_id')->primary();
            $table->foreign('ata_id')->references('ID')->on('tb_ATA');
            $table->char('original_sha256', 64);
            $table->char('redacted_sha256', 64);
            $table->binary('redacted_pdf');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ata_signed_document_copies MODIFY redacted_pdf LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        // Preserve the redacted copies already filed with their signed originals.
    }
};
