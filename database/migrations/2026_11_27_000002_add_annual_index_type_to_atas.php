<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ATA', fn (Blueprint $table) => $table->string('TIPO_INDICE_ANUAL', 20)->nullable());
    }

    public function down(): void
    {
        // Preserve the annual adjustment index choices already recorded.
    }
};
