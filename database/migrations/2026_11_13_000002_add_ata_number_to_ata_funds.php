<?php

use App\Models\ActivityEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits separately; allow a safe retry if the audited backfill fails.
        if (! Schema::hasColumn('tb_FUNDOS_ATA', 'ATA')) {
            Schema::table('tb_FUNDOS_ATA', function (Blueprint $table) {
                $table->string('ATA', 32)->nullable();
            });
        }
        DB::transaction(function () {
            // Include soft-deleted links and atas; retain the exact original number spelling.
            $changed = DB::table('tb_FUNDOS_ATA')->whereNull('ATA')->update([
                'ATA' => DB::raw('(SELECT tb_ATA.ATA FROM tb_ATA WHERE tb_ATA.ID = tb_FUNDOS_ATA.ID_ATA)'),
            ]);
            if ($changed > 0) {
                ActivityEvent::create(['actor_id' => null, 'action' => 'ata_fund_numbers_backfilled',
                    'metadata' => ['source' => 'migration:2026_11_13_000002', 'rows' => $changed,
                        'before' => null, 'after_source' => 'tb_ATA.ATA via tb_FUNDOS_ATA.ID_ATA'], 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Preserve stored ata numbers and the existing relational key.
    }
};
