<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_N_Contrato', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->foreignId('fund_id')->constrained('contract_funds');
            $table->unsignedSmallInteger('ANO');
            $table->text('FUNDO');
            $table->unsignedBigInteger('NUMERO')->default(0);
            $table->foreignId('legacy_record_id')->nullable()->constrained('legacy_records');
            $table->timestamps();
            $table->unique(['fund_id', 'ANO'], 'contract_sequence_fund_year_unique');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('number_year')->nullable();
            $table->unsignedBigInteger('number_sequence')->nullable();
            // Existing historical duplicates remain untouched because these columns are null.
            $table->unique(['fund_id', 'number_year', 'number_sequence'], 'contract_generated_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropUnique('contract_generated_number_unique');
            $table->dropColumn(['number_year', 'number_sequence']);
        });
        Schema::dropIfExists('tb_N_Contrato');
    }
};
