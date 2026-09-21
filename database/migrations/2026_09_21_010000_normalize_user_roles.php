<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->change();
        });

        \DB::table('users')->where('role', 'editor')->update(['role' => 'moderator']);
        \DB::table('users')->where('role', 'viewer')->update(['role' => 'user']);
        \DB::table('users')->where('username', 'editor')->update(['username' => 'moderator']);
    }

    public function down(): void
    {
        \DB::table('users')->where('role', 'moderator')->update(['role' => 'editor']);
        \DB::table('users')->where('role', 'user')->update(['role' => 'viewer']);
        \DB::table('users')->where('username', 'moderator')->update(['username' => 'editor']);
    }
};
