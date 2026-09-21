<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('url', 2048)->unique();
            $table->string('domain', 255)->index();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_scanned_at')->nullable();
            $table->unsignedInteger('last_scan_found')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('presentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('presentation_sources')->nullOnDelete();
            $table->string('title', 500)->nullable();
            $table->string('speaker_name', 255)->nullable();
            $table->string('job_title', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('event_name', 255)->nullable();
            $table->unsignedSmallInteger('event_year')->nullable();
            $table->string('file_type', 8)->index();
            $table->string('file_url', 2048)->unique();
            $table->string('source_page_url', 2048)->nullable();
            $table->string('review_status', 24)->default('new')->index();
            $table->string('link_status', 24)->default('unknown')->index();
            $table->boolean('has_email')->default(false)->index();
            $table->boolean('has_phone')->default(false)->index();
            $table->boolean('is_good_lead')->default(false)->index();
            $table->timestamp('discovered_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentations');
        Schema::dropIfExists('presentation_sources');
    }
};
