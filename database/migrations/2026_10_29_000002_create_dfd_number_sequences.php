<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tb_N_DFD')) {
            Schema::create('tb_N_DFD', function (Blueprint $table) {
                $table->bigIncrements('ID');
                $table->unsignedInteger('N_DFD')->default(0);
            });
        }
        if (! Schema::hasColumn('tb_N_DFD', 'ANO')) {
            Schema::table('tb_N_DFD', function (Blueprint $table) {
                // Existing counters have no reliable year; preserve them unchanged.
                $table->unsignedSmallInteger('ANO')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        // Preserve annual counters and historical records.
    }
};
