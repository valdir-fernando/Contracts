<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', fn (Blueprint $table) => $table->string('report_kind')->default('contracts'));
    }

    public function down(): void
    {
        Schema::table('report_exports', fn (Blueprint $table) => $table->dropColumn('report_kind'));
    }
};
