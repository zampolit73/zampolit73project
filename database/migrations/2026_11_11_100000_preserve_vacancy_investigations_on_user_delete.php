<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve Vacancy Source investigations when an employee account is removed.
 * Previously the user FK cascaded and irreversibly deleted investigation history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancy_investigations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('vacancy_investigations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
        Schema::table('vacancy_investigations', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Preserve orphaned investigations when rolling back rather than
        // allowing a NOT NULL constraint to destroy already saved research.
        // Any prior staff deletion makes the old cascade policy unsafe.
        Schema::table('vacancy_investigations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('vacancy_investigations', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
