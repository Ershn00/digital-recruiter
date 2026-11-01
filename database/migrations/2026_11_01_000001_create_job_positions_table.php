<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->string('location', 200);
            $table->string('employment_type', 30);
            $table->string('interview_language', 10)->default('en');
            $table->string('status', 20)->default('draft');
            $table->jsonb('requirements');
            $table->jsonb('screening_topics');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamps();
            $table->index(['created_by', 'status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_positions');
    }
};
