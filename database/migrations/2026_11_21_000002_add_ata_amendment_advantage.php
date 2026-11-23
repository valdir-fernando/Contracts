<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ADT_ATA', fn (Blueprint $table) => $table->string('VANTAJOSIDADE', 32)->nullable());
    }

    public function down(): void
    {
        // Preserve the recorded justification of each amendment.
    }
};
