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
            $table->string('username')->nullable()->unique();
            $table->unsignedInteger('reference_user_id')->nullable()->unique();
            $table->string('user_type')->nullable();
            $table->text('fund')->nullable();
            $table->text('department')->nullable();
            $table->string('email')->nullable()->change();
        });
        DB::table('users')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update(['username' => 'USER.'.$user->id]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['reference_user_id']);
            $table->dropColumn(['username', 'reference_user_id', 'user_type', 'fund', 'department']);
        });
    }
};
