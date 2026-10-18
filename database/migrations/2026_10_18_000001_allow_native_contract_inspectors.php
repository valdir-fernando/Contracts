<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_inspectors', function (Blueprint $table) {
            $table->unsignedInteger('legacy_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Native records have no legacy ID; preserve them on rollback.
    }
};
