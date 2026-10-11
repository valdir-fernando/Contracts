<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_financial_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained();
            $table->string('kind', 20);
            $table->string('number', 64);
            $table->unsignedSmallInteger('year');
            $table->date('event_date');
            $table->decimal('amount', 18, 4)->nullable();
            $table->string('status', 20)->default('rascunho');
            $table->json('payload');
            $table->foreignId('budget_id')->nullable()->constrained('contract_financial_records');
            $table->foreignId('amendment_id')->nullable()->constrained('contract_amendments');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['contract_id', 'kind', 'year', 'number'], 'financial_record_number_unique');
            $table->index(['contract_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_financial_records');
    }
};
