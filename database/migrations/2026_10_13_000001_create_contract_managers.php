<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_managers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('legacy_id')->unique();
            $table->text('name');
            $table->string('name_key', 255)->index();
            $table->text('document')->nullable();
            $table->string('document_key', 32)->nullable()->index();
            $table->string('fund_name_key', 255)->index();
            $table->json('raw_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_managers');
    }
};
