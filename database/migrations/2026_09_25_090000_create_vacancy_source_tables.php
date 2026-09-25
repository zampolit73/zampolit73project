<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('input_source', 20)->default('web');
            $table->longText('input_text');
            $table->longText('normalized_text')->nullable();
            $table->string('fingerprint', 64)->nullable()->index();
            $table->string('status', 32)->default('queued')->index();
            $table->string('progress_stage', 64)->nullable();
            $table->string('progress_text', 500)->nullable();
            $table->text('result_summary')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('timed_out_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('investigation_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')
                ->constrained('vacancy_investigations')
                ->cascadeOnDelete();
            $table->string('company_name');
            $table->string('candidate_type', 20)->default('indirect');
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->boolean('is_end_client')->default(true);
            $table->unsignedSmallInteger('rank')->default(1);
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->unique(['investigation_id', 'rank']);
            $table->index(['investigation_id', 'confidence']);
        });

        Schema::create('investigation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')
                ->unique()
                ->constrained('vacancy_investigations')
                ->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('status', 20);
            $table->string('correct_client_name')->nullable();
            $table->text('confirmation_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_reviews');
        Schema::dropIfExists('investigation_candidates');
        Schema::dropIfExists('vacancy_investigations');
    }
};
