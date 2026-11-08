<?php

namespace App\Http\Controllers;

use App\Domain\Interviews\Actions\GenerateEvaluation;
use App\Domain\Interviews\Actions\RegenerateInterviewLink;
use App\Domain\Interviews\Actions\StartInterview;
use App\Models\InterviewSession;
use App\Models\JobPosition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InterviewController extends Controller
{
    public function store(Request $request, JobPosition $position, StartInterview $action): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('update', $position);
        $data = $request->validate(['name' => 'required|string|max:200']);
        [$interview, $token] = $action->handle($position, $data['name']);

        return $this->linkConfirmation($request, $interview, $token, false);
    }

    public function regenerateLink(Request $request, InterviewSession $interview, RegenerateInterviewLink $action): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('update', $interview->application->position);
        $data = $request->validate(['link_version' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/D']]);
        [$interview, $token] = $action->handle($request->user(), $interview, $data['link_version']);

        return $this->linkConfirmation($request, $interview, $token, true);
    }

    public function linkRedirect(InterviewSession $interview): RedirectResponse
    {
        Gate::authorize('view', $interview->application->position);

        return to_route('interviews.show', $interview);
    }

    private function linkConfirmation(Request $request, InterviewSession $interview, #[\SensitiveParameter] string $token, bool $regenerated): \Symfony\Component\HttpFoundation\Response
    {
        // Raw tokens are sent only in this no-store response, never session flash, logs or database fields.
        $response = Inertia::render('interviews/created', [
            'interviewId' => $interview->id, 'candidateUrl' => url('/candidate/interviews/'.$token),
            'position' => $interview->application->position->only(['id', 'title']),
            'candidateName' => $interview->application->name,
            'regenerated' => $regenerated, 'expiresAt' => $interview->expires_at->toIso8601String(),
        ])->toResponse($request);
        $response->headers->add(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);

        return $response;
    }

    public function index(JobPosition $position): RedirectResponse
    {
        Gate::authorize('view', $position);

        // Reloading the POST confirmation URL cannot reconstruct a private token or create a new session.
        return redirect(route('positions.show', $position).'#candidates');
    }

    public function show(InterviewSession $interview): Response
    {
        Gate::authorize('view', $interview->application->position);

        return Inertia::render('interviews/show', [
            'interview' => $interview->load(['application', 'messages', 'evaluation']),
            'position' => $interview->application->position->only(['id', 'title']),
            'linkVersion' => $interview->linkVersion(),
            'canRegenerateLink' => $interview->status === 'active' && $interview->completed_at === null && $interview->application->archived_at === null,
            'linkExpired' => ! $interview->expires_at->isFuture(),
        ]);
    }

    public function evaluate(InterviewSession $interview, GenerateEvaluation $action): RedirectResponse
    {
        Gate::authorize('update', $interview->application->position);
        $action->handle($interview);

        return to_route('interviews.show', $interview);
    }
}
