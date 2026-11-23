<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ata_signed_document_copies', 'ata_signed_document_history'] as $name) {
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE `{$name}` MODIFY redacted_pdf LONGBLOB NULL, MODIFY redacted_sha256 CHAR(64) NULL");
            } else {
                Schema::table($name, function (Blueprint $table) {
                    $table->binary('redacted_pdf')->nullable()->change();
                    $table->char('redacted_sha256', 64)->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        // Existing uploads may contain only the original.
    }
};
