<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tb_ATA', 'Doc_ATA')) {
            Schema::table('tb_ATA', function (Blueprint $table) {
                // Relative path on the private local disk; the PDF stays outside the database.
                $table->string('Doc_ATA', 1024)->nullable()->after('ATA');
            });
        }
    }

    public function down(): void
    {
        // Preserve any document references already associated with the atas.
    }
};
