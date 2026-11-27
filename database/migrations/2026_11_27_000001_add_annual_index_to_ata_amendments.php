<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ADT_ATA', fn (Blueprint $table) => $table->string('annual_index', 64)->nullable());
    }

    public function down(): void
    {
        // Preserve the annual indices already recorded in the amendment register.
    }
};
