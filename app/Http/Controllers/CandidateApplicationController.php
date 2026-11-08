<?php

namespace App\Http\Controllers;

use App\Domain\Candidates\Actions\ManageCandidate;
use App\Models\CandidateApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CandidateApplicationController extends Controller
{
    public function update(Request $request, CandidateApplication $candidate, ManageCandidate $action): RedirectResponse
    {
        Gate::authorize('update', $candidate->position);
        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);
        $action->rename($request->user(), $candidate, $data['name']);

        return $this->back($candidate);
    }

    public function archive(Request $request, CandidateApplication $candidate, ManageCandidate $action): RedirectResponse
    {
        Gate::authorize('update', $candidate->position);
        $action->archive($request->user(), $candidate);

        return redirect(route('positions.show', $candidate->job_position_id).'#candidates');
    }

    public function restore(Request $request, CandidateApplication $candidate, ManageCandidate $action): RedirectResponse
    {
        Gate::authorize('update', $candidate->position);
        $action->restore($request->user(), $candidate);

        return redirect(route('positions.show', $candidate->job_position_id).'#candidates');
    }

    public function destroy(Request $request, CandidateApplication $candidate, ManageCandidate $action): RedirectResponse
    {
        Gate::authorize('update', $candidate->position);
        $request->validate(['confirm_delete' => ['required', 'accepted']]);
        $action->delete($request->user(), $candidate);

        return $this->back($candidate);
    }

    private function back(CandidateApplication $candidate): RedirectResponse
    {
        return redirect(route('positions.show', $candidate->job_position_id).($candidate->archived_at !== null ? '?candidates=archived' : '').'#candidates');
    }
}
