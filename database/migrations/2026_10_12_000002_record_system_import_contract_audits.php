<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_audits', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable()->change();
            $table->foreignId('import_run_id')->nullable()->constrained('import_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // System/import history must survive a code rollback.
    }
};
