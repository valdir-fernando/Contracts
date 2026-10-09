<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedInteger('legacy_id')->nullable()->change();
            $table->foreignId('legacy_record_id')->nullable()->change();
        });
        Schema::create('contract_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action');
            $table->json('changes');
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['contract_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_audits');
        // Native contracts have no legacy identifier; retain nullable columns to preserve them.
    }
};
