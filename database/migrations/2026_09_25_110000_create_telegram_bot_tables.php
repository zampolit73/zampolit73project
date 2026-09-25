<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->bigInteger('telegram_chat_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->timestamp('linked_at');
            $table->timestamps();
        });

        Schema::create('telegram_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('used_by_telegram_user_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_invites');
        Schema::dropIfExists('user_telegram_accounts');
    }
};
