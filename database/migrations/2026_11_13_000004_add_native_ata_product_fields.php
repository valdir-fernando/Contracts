<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_PRODUTOS_ATA', function (Blueprint $table) {
            $table->string('COD_PROD', 100)->nullable();
            $table->string('FICHA', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Preserve product codes and ficha history.
    }
};
