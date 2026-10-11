<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('administrative_status')->default('rascunho')->index();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable();
        });
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action')->index();
            $table->json('metadata');
            $table->timestamp('created_at')->index();
        });
        Schema::create('contract_history_records', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->index();
            $table->string('legacy_id', 64);
            $table->foreignId('legacy_record_id')->constrained();
            $table->foreignId('contract_id')->nullable()->constrained();
            $table->string('match_status', 24)->index();
            $table->json('candidate_ids');
            $table->string('contract_number')->nullable();
            $table->text('description')->nullable();
            $table->date('event_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->json('quality_issues');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_reason')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_history_records');
        Schema::dropIfExists('activity_events');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_login_at'));
        Schema::table('contracts', fn (Blueprint $table) => $table->dropColumn('administrative_status'));
    }
};
