<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_type_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->json('required_fields');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_type_rules');
    }
};
