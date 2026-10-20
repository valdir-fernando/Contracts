<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['Contrato_PDF', 'Aditivo_PDF', 'OS_PDF', 'Concordancia_PDF', 'Distrato_PDF', 'ATA_PDF'] as $column) {
                $table->foreignId($column)->nullable()->constrained('contract_attachments')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Preserve signed document references and the associated history.
    }
};
