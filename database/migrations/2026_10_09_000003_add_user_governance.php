<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedInteger('session_version')->default(0);
        });
        Schema::create('access_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 64)->unique();
            $table->text('fund');
            $table->text('department')->nullable();
        });
        Schema::create('access_scope_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_scope_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'access_scope_id']);
        });
        Schema::create('user_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            if (! trim($user->fund ?? '')) {
                continue;
            }
            $fund = trim($user->fund);
            $department = trim($user->department ?? '');
            $key = hash('sha256', $fund.'|'.$department);
            DB::table('access_scopes')->insertOrIgnore(['scope_key' => $key, 'fund' => $fund, 'department' => $department ?: null]);
            $scope = DB::table('access_scopes')->where('scope_key', $key)->value('id');
            DB::table('access_scope_user')->insert(['user_id' => $user->id, 'access_scope_id' => $scope]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_audits');
        Schema::dropIfExists('access_scope_user');
        Schema::dropIfExists('access_scopes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['deleted_at', 'session_version']));
    }
};
