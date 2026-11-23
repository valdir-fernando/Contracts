<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = 'tb_PRODUTOS_ATA';
        $replacement = 'ata_product_active_term_item_unique';
        if (! Schema::hasColumn($table, 'amendment_active_slot')) {
            Schema::table($table, fn (Blueprint $table) => $table->unsignedTinyInteger('amendment_active_slot')
                ->nullable()->virtualAs('CASE WHEN ADITIVO = 0 OR deleted_at IS NULL THEN 1 ELSE NULL END'));
        }
        // Keep an index starting with ID_ATA before removing the previous uniqueness constraint.
        if (! Schema::hasIndex($table, $replacement)) {
            Schema::table($table, fn (Blueprint $table) => $table
                ->unique(['ID_ATA', 'ADITIVO', 'ITEM', 'ficha_identity', 'amendment_active_slot'], $replacement));
        }
        if (Schema::hasIndex($table, 'ata_product_ficha_item_unique')) {
            Schema::table($table, fn (Blueprint $table) => $table->dropUnique('ata_product_ficha_item_unique'));
        }
    }

    public function down(): void
    {
        // Historical products can share the same amendment number after logical deletion.
    }
};
