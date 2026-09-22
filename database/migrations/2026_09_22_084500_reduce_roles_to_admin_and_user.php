<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotIn('role', ['admin', 'user'])
            ->update(['role' => 'user']);
    }

    public function down(): void
    {
        // The previous role cannot be reconstructed safely.
    }
};
