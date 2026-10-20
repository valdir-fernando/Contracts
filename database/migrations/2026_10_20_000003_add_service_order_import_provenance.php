<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_OS', function (Blueprint $table) {
            $table->foreignId('legacy_record_id')->nullable()->unique()->constrained('legacy_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Preserve import provenance and historical records.
    }
};
