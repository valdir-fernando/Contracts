<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ATA', function (Blueprint $table) {
            $table->date('DATA_ORCAMENTO')->nullable();
        });
    }

    public function down(): void
    {
        // Preserve the budget dates already recorded.
    }
};
