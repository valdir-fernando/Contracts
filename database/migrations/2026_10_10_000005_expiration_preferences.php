<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('expiration_alert_enabled')->default(true);
            $table->unsignedSmallInteger('expiration_alert_days')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['expiration_alert_enabled', 'expiration_alert_days']));
    }
};
