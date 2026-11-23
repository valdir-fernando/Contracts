<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ATA', fn (Blueprint $table) => $table->date('DATA_PUBLIC_INPC')->nullable());
    }

    public function down(): void
    {
        // Preserve recorded publication dates and their editing lock.
    }
};
