<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_research_telegram_pending', function (Blueprint $table) {
            // Persist the outcome so a retried Telegram callback can report
            // the ORIGINAL saved revision rather than the latest revision.
            $table->unsignedInteger('result_revision')->nullable();
            $table->boolean('result_changed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_research_telegram_pending', function (Blueprint $table) {
            $table->dropColumn(['result_revision', 'result_changed']);
        });
    }
};
