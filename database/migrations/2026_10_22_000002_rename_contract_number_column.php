<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', fn (Blueprint $table) => $table->renameColumn('numero', 'Contrato'));
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $table) => $table->renameColumn('Contrato', 'numero'));
    }
};
