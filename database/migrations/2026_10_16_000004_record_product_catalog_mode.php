<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_Produtos_Contratos', fn (Blueprint $table) => $table->boolean('process_catalog')->default(false));
    }

    public function down(): void
    {
        // Preserve the recorded distribution rule and history.
    }
};
