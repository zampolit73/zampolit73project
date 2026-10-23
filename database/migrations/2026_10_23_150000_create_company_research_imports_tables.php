<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_research_imports', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 80)->unique();
            $table->string('company_inn', 12)->nullable()->unique();
            $table->string('company_name', 200);
            $table->longText('payload');
            $table->char('payload_sha256', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('company_research_import_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_import_id')->constrained('company_research_imports')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->longText('payload');
            $table->char('payload_sha256', 64);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['research_import_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_research_import_revisions');
        Schema::dropIfExists('company_research_imports');
    }
};
