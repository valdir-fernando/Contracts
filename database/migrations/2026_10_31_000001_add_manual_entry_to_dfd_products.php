<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_Produtos_DFD') && ! Schema::hasColumn('tb_Produtos_DFD', 'manual_entry')) {
            Schema::table('tb_Produtos_DFD', fn (Blueprint $table) => $table->boolean('manual_entry')->default(false));
        }
    }

    public function down(): void
    {
        // Preserve manual product history.
    }
};
