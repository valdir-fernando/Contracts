<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('catalog_permissions')->nullable();
        });
    }

    public function down(): void
    {
        // Preserve existing permission history and data.
    }
};
