<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tb_ATA', 'VIGENCIA_ANO_INPC')) {
            Schema::table('tb_ATA', fn (Blueprint $table) => $table->boolean('VIGENCIA_ANO_INPC')->nullable());
        }
    }

    public function down(): void
    {
        // Preserve the recorded validity mode and historical data.
    }
};
