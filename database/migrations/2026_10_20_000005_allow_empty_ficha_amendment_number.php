<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ValorFicha', fn (Blueprint $table) => $table->integer('N_ADT')->nullable()->default(null)->change());
    }

    public function down(): void
    {
        // Keep empty original-contract markers and all existing financial records.
    }
};
