<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ata_signed_document_copies', fn (Blueprint $table) => $table->softDeletes());
        Schema::create('ata_signed_document_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ata_id')->index();
            $table->foreign('ata_id')->references('ID')->on('tb_ATA');
            $table->binary('original_pdf');
            $table->binary('redacted_pdf');
            $table->char('original_sha256', 64);
            $table->char('redacted_sha256', 64);
            $table->timestamp('deleted_at');
        });
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ata_signed_document_history MODIFY original_pdf LONGBLOB NOT NULL, MODIFY redacted_pdf LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        // Preserve removed documents and their history.
    }
};
