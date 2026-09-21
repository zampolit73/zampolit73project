<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->updateOrInsert(
            ['username' => 'admin'],
            [
                'role' => 'admin',
                'password' => '$2y$12$3B8pr6nsJVp.x4dvpzgT7e37c1O7Ll1tINKJ3p1rqJs4hg5iLc96m',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // Intentionally left empty: never delete a production administrator on rollback.
    }
};
