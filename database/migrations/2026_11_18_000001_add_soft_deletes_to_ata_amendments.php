<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tb_ADITIVOS_ATA', 'tb_ADT_ATA'] as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->softDeletes());
            }
        }
    }

    public function down(): void
    {
        // Preserve deletion history and existing amendment records.
    }
};
