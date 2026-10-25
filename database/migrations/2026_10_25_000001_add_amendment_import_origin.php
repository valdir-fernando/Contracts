<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_amendments', function (Blueprint $table) {
            $table->foreignId('legacy_record_id')->nullable()->unique()->constrained('legacy_records');
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->unsignedBigInteger('updated_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Imported origins and system authorship must survive rollback.
    }
};
