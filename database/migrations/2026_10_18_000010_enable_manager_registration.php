<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_managers', function (Blueprint $table) {
            $table->unsignedInteger('legacy_id')->nullable()->change();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Preserve native records and deletion history.
    }
};
