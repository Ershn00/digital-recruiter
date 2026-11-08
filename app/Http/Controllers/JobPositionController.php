<?php

namespace App\Http\Controllers;

use App\Domain\Positions\Actions\ArchivePosition;
use App\Domain\Positions\Actions\SavePosition;
use App\Domain\Positions\Enums\EmploymentType;
use App\Domain\Positions\Enums\InterviewLanguage;
use App\Domain\Positions\Enums\PositionStatus;
use App\Http\Requests\Positions\SavePositionRequest;
use App\Models\CandidateApplication;
use App\Models\JobPosition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class JobPositionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', JobPosition::class);

        return Inertia::render('positions/index', ['positions' => JobPosition::query()->where('created_by', $request->user()->id)->latest('updated_at')->orderByDesc('id')->paginate(15, ['id', 'title', 'location', 'employment_type', 'status', 'updated_at'])]);
    }

    public function create(): Response
    {
        Gate::authorize('create', JobPosition::class);

        return Inertia::render('positions/create', $this->options());
    }

    public function store(SavePositionRequest $request, SavePosition $action): RedirectResponse
    {
        $position = $action->handle($request->user(), $request->validated());

        return to_route('positions.show', $position);
    }

    public function show(Request $request, JobPosition $position): Response
    {
        Gate::authorize('view', $position);

        $archived = $request->query('candidates') === 'archived';

        return Inertia::render('positions/show', [
            'position' => $position, 'candidateFilter' => $archived ? 'archived' : 'active',
            'activeCandidateCount' => $position->applications()->whereNull('archived_at')->count(),
            'archivedCandidateCount' => $position->applications()->whereNotNull('archived_at')->count(),
            'applications' => $position->applications()->when($archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))->with('interview')->latest()->get()->map(function (CandidateApplication $application): CandidateApplication {
                $application->setAttribute('interview_url', $application->interview ? route('interviews.show', ['interview' => $application->interview->id], false) : null);

                $application->setAttribute('can_delete', $application->canDeleteForDemo());

                return $application;
            })]);
    }

    public function edit(JobPosition $position): Response|RedirectResponse
    {
        Gate::authorize('update', $position);
        if ($position->status === PositionStatus::Archived) {
            return to_route('positions.show', $position);
        }

        return Inertia::render('positions/edit', ['position' => $position, ...$this->options()]);
    }

    public function update(SavePositionRequest $request, JobPosition $position, SavePosition $action): RedirectResponse
    {
        $action->handle($request->user(), $request->validated(), $position);

        return to_route('positions.show', $position);
    }

    public function archive(Request $request, JobPosition $position, ArchivePosition $action): RedirectResponse
    {
        Gate::authorize('archive', $position);
        $action->handle($request->user(), $position);

        return to_route('positions.show', $position);
    }

    /** @return array<string, list<string>> */
    private function options(): array
    {
        return ['employmentTypes' => array_column(EmploymentType::cases(), 'value'), 'languages' => array_column(InterviewLanguage::cases(), 'value')];
    }
}
