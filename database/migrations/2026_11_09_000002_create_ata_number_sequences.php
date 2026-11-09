<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_N_ATA', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->unsignedSmallInteger('ANO')->unique();
            $table->unsignedBigInteger('NUMERO')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Preserve annual reservations and avoid reusing issued numbers.
    }
};
