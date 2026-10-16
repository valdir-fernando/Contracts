<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_Produtos_Contratos', fn (Blueprint $table) => $table->unsignedBigInteger('product_id')->nullable()->change());
    }

    public function down(): void
    {
        // Preserve manual entries and their history.
    }
};
