<?php

namespace App\Domain\Interviews\Actions;

use App\Models\InterviewSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RegenerateInterviewLink
{
    /** @return array{InterviewSession, string} */
    public function handle(User $recruiter, InterviewSession $interview, string $expectedVersion): array
    {
        return DB::transaction(function () use ($recruiter, $interview, $expectedVersion): array {
            $session = InterviewSession::query()->lockForUpdate()->findOrFail($interview->id);
            Gate::forUser($recruiter)->authorize('update', $session->application->position);
            if ($session->status !== 'active' || $session->completed_at !== null || $session->application->archived_at !== null) {
                throw ValidationException::withMessages(['link_version' => 'Only unfinished interviews for active candidates can receive a new link. Restore archived candidates first.']);
            }
            if (! hash_equals($session->linkVersion(), $expectedVersion)) {
                throw ValidationException::withMessages(['link_version' => 'The interview link changed in another request. Refresh the interview detail before generating another link.']);
            }

            $token = bin2hex(random_bytes(32));
            // Only these link fields change. In-flight AI work, progress and transcripts stay intact.
            $session->update(['access_token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(24)]);

            return [$session, $token];
        });
    }
}
