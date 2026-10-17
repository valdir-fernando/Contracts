<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedSmallInteger('administration_start_year');
            $table->unsignedSmallInteger('administration_end_year');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::table('document_settings')->insert(['id' => 1, 'administration_start_year' => 2025,
            'administration_end_year' => 2028, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Preserve document settings and their history.
    }
};
