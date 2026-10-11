<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['contract_funds', 'contract_departments', 'suppliers'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedInteger('legacy_id')->nullable()->change());
        }
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('format', 8);
            $table->json('filters');
            $table->string('access_hash', 64);
            $table->string('status')->default('pending');
            $table->string('path')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        // Native catalogs cannot safely be converted back to mandatory legacy identifiers.
    }
};
