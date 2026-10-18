<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_Gestor_Cont', function (Blueprint $table) {
            $table->increments('ID_Gestor_Cont');
            foreach (['CPF_Gestor', 'RG_Gestor', 'Data_Nasc', 'Sexo', 'Nome_Gestor', 'Email', 'Fundo', 'Secretaria',
                'Decreto', 'Data_Decreto', 'Celular', 'WhatsApp'] as $field) {
                $table->text($field)->nullable();
            }
            $table->integer('Cod_Prodata')->nullable();
            $table->foreignId('contract_steward_id')->unique()->constrained('contract_stewards');
            $table->timestamps();
            $table->softDeletes();
        });
        DB::transaction(function () {
            DB::table('contract_stewards')->orderBy('id')->chunkById(100, function ($records) {
                foreach ($records as $record) {
                    $raw = json_decode($record->raw_payload, true, 512, JSON_THROW_ON_ERROR);
                    $values = array_fill_keys(['CPF_Gestor', 'RG_Gestor', 'Data_Nasc', 'Sexo', 'Email', 'Fundo',
                        'Secretaria', 'Decreto', 'Data_Decreto', 'Celular', 'WhatsApp', 'Cod_Prodata'], null);
                    $values = array_replace($values, array_intersect_key($raw, $values));
                    DB::table('tb_Gestor_Cont')->insert($values + ['Nome_Gestor' => $record->name,
                        'contract_steward_id' => $record->id, 'created_at' => $record->created_at,
                        'updated_at' => $record->updated_at, 'deleted_at' => $record->deleted_at]);
                }
            });
        });
    }

    public function down(): void
    {
        // Preserve registration data and history on rollback.
    }
};
