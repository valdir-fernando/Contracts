<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_Historico', function (Blueprint $table) {
            $table->text('Fiscal')->change();
        });
    }

    public function down(): void
    {
        // Preserve full inspector names and decrees.
    }
};
