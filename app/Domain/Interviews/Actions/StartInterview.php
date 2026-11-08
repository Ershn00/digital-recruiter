<?php

namespace App\Domain\Interviews\Actions;

use App\Domain\Positions\Enums\PositionStatus;
use App\Models\InterviewSession;
use App\Models\JobPosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartInterview
{
    /** @return array{InterviewSession, string} */
    public function handle(JobPosition $position, string $name): array
    {
        return DB::transaction(function () use ($position, $name) {
            $position = JobPosition::query()->lockForUpdate()->findOrFail($position->id);
            if ($position->status !== PositionStatus::Active || ! $position->requirements || ! $position->screening_topics) {
                throw ValidationException::withMessages(['name' => 'Activate the position and save requirements and screening topics first.']);
            }
            $token = bin2hex(random_bytes(32));
            $application = $position->applications()->create(['name' => $name, 'fictional' => true]);
            $interview = $application->interview()->create([
                'access_token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(24),
                'criteria_snapshot' => $position->only(['title', 'description', 'version', 'interview_language', 'requirements', 'screening_topics']),
            ]);

            return [$interview, $token];
        });
    }
}
