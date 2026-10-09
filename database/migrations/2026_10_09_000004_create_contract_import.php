<?php

use App\Support\ContractFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['contract_funds', 'contract_departments', 'suppliers'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->unsignedInteger('legacy_id')->unique();
                $table->text('name');
                $table->string('name_key', 255)->index();
                if ($name !== 'contract_departments') {
                    $table->text('document')->nullable();
                    $table->string('document_key', 32)->nullable()->index();
                }
                $table->json('raw_payload');
                $table->timestamps();
            });
        }
        Schema::table('access_scopes', function (Blueprint $table) {
            $table->foreignId('fund_id')->nullable()->constrained('contract_funds');
            $table->foreignId('department_id')->nullable()->constrained('contract_departments');
        });
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_hash', 64);
            $table->string('status');
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('issues_count')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });
        Schema::create('legacy_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->string('legacy_id', 64);
            $table->string('source_hash', 64);
            $table->json('raw_payload');
            $table->string('status')->default('pending');
            $table->foreignId('import_run_id')->constrained();
            $table->unique(['source_table', 'legacy_id', 'source_hash'], 'legacy_version_unique');
        });
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            foreach (ContractFields::MAP as $definition) {
                $column = $definition['column'];
                match ($definition['type']) {
                    'id' => $table->unsignedInteger($column)->unique(),
                    'date' => $table->date($column)->nullable(),
                    'money' => $table->decimal($column, 18, 4)->nullable(),
                    default => $table->text($column)->nullable(),
                };
            }
            $table->foreignId('legacy_record_id')->constrained();
            $table->foreignId('fund_id')->nullable()->constrained('contract_funds');
            $table->foreignId('department_id')->nullable()->constrained('contract_departments');
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->string('supplier_document_key', 32)->nullable()->index();
            $table->unsignedSmallInteger('exercicio')->nullable()->index();
            $table->boolean('ownership_resolved')->default(false)->index();
            $table->boolean('vigencia_em_revisao')->default(false);
            $table->json('quality_issues');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['fund_id', 'department_id']);
            $table->index('vigencia_fim_atual');
            $table->index('vigencia_fim_original');
            $table->index('data_contrato');
        });
        Schema::create('import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained();
            $table->foreignId('legacy_record_id')->nullable()->constrained();
            $table->string('source_table', 64);
            $table->string('legacy_id', 64);
            $table->string('field', 100)->nullable();
            $table->string('code', 64)->index();
            $table->string('severity', 16)->default('warning');
            $table->text('message');
            $table->index(['source_table', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('legacy_records');
        Schema::dropIfExists('import_runs');
        Schema::table('access_scopes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fund_id');
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('contract_departments');
        Schema::dropIfExists('contract_funds');
    }
};
