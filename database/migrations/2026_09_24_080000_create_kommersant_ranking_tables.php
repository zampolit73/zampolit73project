<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kommersant_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('label', 120);
            $table->string('title', 255);
            $table->text('source_note')->nullable();
            $table->string('pdf_pages', 32)->nullable();
            $table->string('newspaper_pages', 32)->nullable();
            $table->unsignedSmallInteger('position')->index();
            $table->timestamps();
        });

        Schema::create('kommersant_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('kommersant_categories')->cascadeOnDelete();
            $table->unsignedSmallInteger('row_number');
            $table->string('industry', 255)->nullable()->index();
            $table->unsignedSmallInteger('place')->nullable();
            $table->string('full_name', 255)->index();
            $table->text('linkedin_url')->nullable();
            $table->string('job_title', 255)->nullable();
            $table->string('company', 255)->nullable()->index();
            $table->unsignedSmallInteger('pdf_page')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['category_id', 'row_number']);
            $table->index(['category_id', 'assigned_to_user_id']);
        });

        Schema::create('kommersant_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 255)->index();
            $table->string('company', 255)->nullable()->index();
            $table->string('job_title', 255)->nullable();
            $table->text('linkedin_url')->nullable();
            $table->string('confidence_status', 80)->nullable()->index();
            $table->text('result_reason')->nullable();
            $table->text('source_check')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('kommersant_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_name', 255);
            $table->string('action', 64)->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });

        $dataDir = database_path('data/kommersant-ranking-2026');
        $encoded = '';
        foreach (glob($dataDir.'/data-*.txt') as $file) {
            $encoded .= trim((string) file_get_contents($file));
        }

        $compressed = base64_decode($encoded, true);
        if ($compressed === false) {
            throw new RuntimeException('Invalid Kommersant ranking seed payload.');
        }

        $json = gzdecode($compressed);
        if ($json === false) {
            throw new RuntimeException('Cannot decompress Kommersant ranking seed payload.');
        }

        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $now = now();

        $categories = [];
        foreach ($payload['categories'] as $category) {
            $categories[] = [
                'slug' => $category['slug'],
                'label' => $category['label'],
                'title' => $category['title'],
                'source_note' => $category['source_note'],
                'pdf_pages' => $category['pdf_pages'],
                'newspaper_pages' => $category['newspaper_pages'],
                'position' => $category['position'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('kommersant_categories')->insert($categories);

        $categoryIds = DB::table('kommersant_categories')->pluck('id', 'slug');
        $managerRows = [];
        foreach ($payload['categories'] as $category) {
            $categoryId = $categoryIds[$category['slug']];
            foreach ($category['records'] as $record) {
                $managerRows[] = [
                    'category_id' => $categoryId,
                    'row_number' => $record['row_number'],
                    'industry' => $record['industry'],
                    'place' => $record['place'],
                    'full_name' => $record['full_name'],
                    'linkedin_url' => $record['linkedin_url'],
                    'job_title' => $record['job_title'],
                    'company' => $record['company'],
                    'pdf_page' => $record['pdf_page'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($managerRows, 200) as $chunk) {
            DB::table('kommersant_managers')->insert($chunk);
        }

        $candidateRows = [];
        foreach ($payload['candidates'] as $candidate) {
            $candidateRows[] = [
                'full_name' => $candidate['full_name'],
                'company' => $candidate['company'],
                'job_title' => $candidate['job_title'],
                'linkedin_url' => $candidate['linkedin_url'],
                'confidence_status' => $candidate['confidence_status'],
                'result_reason' => $candidate['result_reason'],
                'source_check' => $candidate['source_check'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($candidateRows, 200) as $chunk) {
            DB::table('kommersant_candidates')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kommersant_activities');
        Schema::dropIfExists('kommersant_candidates');
        Schema::dropIfExists('kommersant_managers');
        Schema::dropIfExists('kommersant_categories');
    }
};
