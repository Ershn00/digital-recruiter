<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });
        foreach (['interview_sessions' => ['candidate_application_id', 'candidate_applications'], 'interview_messages' => ['interview_session_id', 'interview_sessions'], 'candidate_evaluations' => ['interview_session_id', 'interview_sessions']] as $tableName => [$column, $parent]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $parent) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on($parent)->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['interview_sessions' => ['candidate_application_id', 'candidate_applications'], 'interview_messages' => ['interview_session_id', 'interview_sessions'], 'candidate_evaluations' => ['interview_session_id', 'interview_sessions']] as $tableName => [$column, $parent]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $parent) {
                $table->dropForeign([$column]);
                $table->foreign($column)->references('id')->on($parent)->cascadeOnDelete();
            });
        }
        Schema::table('candidate_applications', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
