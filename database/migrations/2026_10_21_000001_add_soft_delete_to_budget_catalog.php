<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tb_Dotacao', 'deleted_at')) {
            Schema::table('tb_Dotacao', fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable());
        }
    }

    public function down(): void {}
};
