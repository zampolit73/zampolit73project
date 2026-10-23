<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_research_telegram_pending', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('telegram_user_id');
            $table->bigInteger('telegram_chat_id');
            $table->bigInteger('telegram_message_id');
            $table->string('report_id', 80);
            $table->string('company_name', 200);
            $table->boolean('had_collision')->default(false);
            $table->longText('payload')->nullable();
            $table->char('token_hash', 64);
            $table->string('state', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'telegram_message_id'], 'telegram_research_message_unique');
            $table->index(['state', 'expires_at'], 'telegram_research_pending_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_research_telegram_pending');
    }
};
