<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tb_ATA', 'ADMITIR_ADESAO')) {
            Schema::table('tb_ATA', fn (Blueprint $table) => $table->string('ADMITIR_ADESAO', 3)->nullable());
        }
    }

    public function down(): void
    {
        // Preserve the recorded admission decisions.
    }
};
