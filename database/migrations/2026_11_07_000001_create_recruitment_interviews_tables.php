<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_position_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->boolean('fictional')->default(true);
            $table->timestamps();
        });
        Schema::create('interview_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('access_token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->jsonb('criteria_snapshot');
            $table->string('status')->default('active');
            $table->unsignedInteger('question_index')->default(0);
            $table->boolean('follow_up_used')->default(false);
            $table->uuid('processing_id')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('interview_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_session_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->uuid('submission_id')->nullable();
            $table->timestamps();
            $table->unique(['interview_session_id', 'submission_id']);
        });
        Schema::create('candidate_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('result');
            $table->string('model');
            $table->string('prompt_version')->default('demo-v1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_evaluations');
        Schema::dropIfExists('interview_messages');
        Schema::dropIfExists('interview_sessions');
        Schema::dropIfExists('candidate_applications');
    }
};
