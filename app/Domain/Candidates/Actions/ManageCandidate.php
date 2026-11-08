<?php

namespace App\Domain\Candidates\Actions;

use App\Models\CandidateApplication;
use App\Models\InterviewSession;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageCandidate
{
    public function rename(User $user, CandidateApplication $candidate, string $name): void
    {
        $this->locked($user, $candidate, function (CandidateApplication $record) use ($name): void {
            $record->update(['name' => $name]);
        });
    }

    public function archive(User $user, CandidateApplication $candidate): void
    {
        $this->locked($user, $candidate, function (CandidateApplication $record, ?InterviewSession $interview): void {
            if ($record->archived_at !== null) {
                return;
            }
            $this->revoke($interview);
            $record->update(['archived_at' => now()]);
        });
    }

    public function restore(User $user, CandidateApplication $candidate): void
    {
        $this->locked($user, $candidate, function (CandidateApplication $record, ?InterviewSession $interview): void {
            if ($record->archived_at === null) {
                return;
            }
            // Restore visibility, never an old access credential, including legacy archived records.
            $this->revoke($interview);
            $record->update(['archived_at' => null]);
        });
    }

    public function delete(User $user, CandidateApplication $candidate): void
    {
        try {
            $this->locked($user, $candidate, function (CandidateApplication $record, ?InterviewSession $interview): void {
                if (! $record->canDeleteForDemo()) {
                    throw ValidationException::withMessages(['candidate' => 'Only unused fictional demo candidates can be deleted. Archive candidates with interview history instead.']);
                }
                // No cascade is used: restrictive foreign keys defend against concurrent/future dependent records.
                $interview?->delete();
                $record->delete();
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['candidate' => 'This candidate has dependent recruitment records and cannot be deleted. Archive it instead.']);
            }
            throw $exception;
        }
    }

    private function revoke(?InterviewSession $interview): void
    {
        if ($interview) {
            $interview->update(['access_token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => now()]);
        }
    }

    /** @param Closure(CandidateApplication, ?InterviewSession): void $operation */
    private function locked(User $user, CandidateApplication $candidate, Closure $operation): void
    {
        DB::transaction(function () use ($user, $candidate, $operation): void {
            $record = CandidateApplication::query()->lockForUpdate()->findOrFail($candidate->id);
            Gate::forUser($user)->authorize('update', $record->position);
            $interview = InterviewSession::where('candidate_application_id', $record->id)->lockForUpdate()->first();
            $record->setRelation('interview', $interview);
            $operation($record, $interview);
        });
    }
}
