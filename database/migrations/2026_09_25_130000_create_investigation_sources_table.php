<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')
                ->constrained('vacancy_investigations')
                ->cascadeOnDelete();
            $table->foreignId('candidate_id')
                ->nullable()
                ->constrained('investigation_candidates')
                ->nullOnDelete();
            $table->string('provider', 32);
            $table->string('title', 500)->nullable();
            $table->text('url');
            $table->text('snippet')->nullable();
            $table->text('search_query')->nullable();
            $table->unsignedTinyInteger('evidence_score')->default(0);
            $table->timestamps();

            $table->index(['investigation_id', 'evidence_score']);
            $table->index(['candidate_id', 'evidence_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_sources');
    }
};
