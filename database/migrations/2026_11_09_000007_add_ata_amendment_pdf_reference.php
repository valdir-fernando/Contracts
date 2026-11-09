<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tb_ADT_ATA', 'Doc_ADT')) {
            Schema::table('tb_ADT_ATA', function (Blueprint $table) {
                // Relative path on the private local disk, following tb_ATA.Doc_ATA.
                $table->string('Doc_ADT', 1024)->nullable()->after('Tipo_ADT');
            });
        }
    }

    public function down(): void
    {
        // Preserve PDF references already associated with amendment records.
    }
};
