<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_prospects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('normalized_name', 200)->unique();
            $table->string('inn', 12)->nullable()->unique();
            $table->string('website', 2048)->nullable();
            $table->string('status', 24)->default('new')->index();
            $table->string('priority', 10)->default('medium');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_name', 200)->nullable();
            $table->string('contact_role', 200)->nullable();
            $table->string('contact_url', 2048)->nullable();
            $table->string('evidence_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->text('next_step')->nullable();
            $table->foreignId('source_candidate_id')->nullable()->unique()->constrained('investigation_candidates')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_prospects');
    }
};
