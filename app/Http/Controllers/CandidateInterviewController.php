<?php

namespace App\Http\Controllers;

use App\Domain\Interviews\Actions\AdvanceInterview;
use App\Models\InterviewSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class CandidateInterviewController extends Controller
{
    private function session(string $token): InterviewSession
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 404);

        return InterviewSession::where('access_token_hash', hash('sha256', $token))->where('expires_at', '>', now())->whereHas('application', fn ($query) => $query->whereNull('archived_at'))->firstOrFail();
    }

    public function show(string $token): Response
    {
        $session = $this->session($token);

        $props = ['interview' => [
            'id' => $session->id, 'title' => $session->criteria_snapshot['title'], 'status' => $session->status,
            'language' => $session->criteria_snapshot['interview_language'],
        ]];
        $messages = $session->messages()->get(['id', 'role', 'content', 'submission_id']);
        $last = $messages->last();
        $props['interview']['last_submission_id'] = $messages->where('role', 'candidate')->last()?->submission_id;
        $completed = $session->status === 'completed';
        if (! $completed) {
            // Candidate presentation is a projection of persisted state; the recruiter transcript is unchanged.
            $props['interview']['current_question'] = $last?->role === 'assistant'
                ? ['id' => $last->id, 'content' => $last->content] : null;
            $props['interview']['question_number'] = $messages->where('role', 'assistant')->count();
            $props['interview']['started'] = $messages->isNotEmpty();
            $props['interview']['answer_saved'] = $last?->role === 'candidate';
            $props['interview']['completed_topics'] = min($session->question_index, count($session->criteria_snapshot['screening_topics']));
            $props['interview']['total_topics'] = count($session->criteria_snapshot['screening_topics']);
            $props['token'] = $token;
        }
        $response = Inertia::render($completed ? 'candidate/completed' : 'candidate/interview', $props)->toResponse(request());
        $response->headers->add([
            'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);

        return $response;
    }

    public function update(Request $request, string $token, AdvanceInterview $action): RedirectResponse
    {
        $session = $this->session($token);
        $data = $request->validate([
            'operation' => 'required|in:answer,resume',
            'content' => 'required_if:operation,answer|string|max:6000',
            'submission_id' => 'required_if:operation,answer|uuid',
            'reply_to' => 'required_if:operation,answer|integer',
        ]);
        $action->handle($session, $data['operation'], $data['operation'] === 'answer' ? $data : null);

        return to_route('candidate.interviews.show', ['token' => $token]);
    }
}
