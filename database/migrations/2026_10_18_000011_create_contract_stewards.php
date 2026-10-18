<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_stewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id')->nullable()->unique();
            $table->text('name');
            $table->string('name_key', 255)->index();
            $table->string('fund_name_key', 255)->index();
            $table->string('department_name_key', 255)->default('')->index();
            $table->json('raw_payload');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Preserve registration records and history on rollback.
    }
};
