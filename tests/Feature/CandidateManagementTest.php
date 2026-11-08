<?php

use App\Domain\Candidates\Actions\ManageCandidate;
use App\Domain\Interviews\Actions\AdvanceInterview;
use App\Domain\Interviews\Actions\RegenerateInterviewLink;
use App\Domain\Interviews\Actions\StartInterview;
use App\Models\CandidateApplication;
use App\Models\InterviewSession;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
});

function candidateManagementFixture(): array
{
    $owner = User::factory()->create();
    $position = new JobPosition([
        'title' => 'Senior Laravel Developer', 'description' => 'Demo role', 'location' => 'Remote',
        'employment_type' => 'full_time', 'interview_language' => 'tr', 'status' => 'active',
        'requirements' => [['id' => (string) Str::uuid(), 'text' => 'Laravel', 'category' => 'essential', 'display_order' => 0]],
        'screening_topics' => [['id' => (string) Str::uuid(), 'text' => 'Laravel?', 'required' => true, 'requirement_id' => null, 'display_order' => 0]],
    ]);
    $position->created_by = $owner->id;
    $position->save();
    [$session, $token] = app(StartInterview::class)->handle($position, 'Fictional Candidate');

    return [$owner, $position, $session->application, $session->fresh(), $token];
}

test('owner edits only the display name and preserves candidate identity interview links and evidence', function () {
    [$owner, $position, $candidate, $session, $token] = candidateManagementFixture();
    $message = $session->messages()->create(['role' => 'candidate', 'content' => 'Historical answer']);
    $evaluation = $session->evaluation()->create(['model' => 'test-model', 'result' => ['criteria' => [], 'strengths' => [], 'gaps' => []]]);
    $beforeSession = $session->fresh()->getAttributes();
    $beforeMessage = $message->fresh()->getAttributes();
    $beforeEvaluation = $evaluation->fresh()->getAttributes();
    $this->actingAs($owner)->patch("/candidates/{$candidate->id}", [
        'name' => '  Renamed Candidate  ', 'fictional' => false, 'job_position_id' => 999, 'archived_at' => now()->toIso8601String(),
        'status' => 'completed', 'answers' => ['overwritten'], 'interview' => ['status' => 'completed'],
    ])->assertRedirect(route('positions.show', $position).'#candidates');
    expect($candidate->fresh()->name)->toBe('Renamed Candidate')->and($candidate->fresh()->fictional)->toBeTrue()
        ->and($candidate->fresh()->job_position_id)->toBe($position->id)->and($candidate->fresh()->archived_at)->toBeNull()
        ->and($session->fresh()->getAttributes())->toBe($beforeSession)
        ->and($message->fresh()->getAttributes())->toBe($beforeMessage)->and($evaluation->fresh()->getAttributes())->toBe($beforeEvaluation);
    $this->get("/candidate/interviews/$token")->assertOk();
});

test('candidate name validation rejects invalid values', function (mixed $name) {
    [$owner, , $candidate] = candidateManagementFixture();
    $this->actingAs($owner)->patch("/candidates/{$candidate->id}", ['name' => $name])->assertSessionHasErrors('name');
    expect($candidate->fresh()->name)->toBe('Fictional Candidate');
})->with(['empty' => [''], 'whitespace' => ['   '], 'too long' => [str_repeat('a', 201)], 'array' => [['invalid']]]);

test('archive hides candidates from the active list preserves evidence and blocks issued links and regeneration', function () {
    [$owner, $position, $candidate, $session, $token] = candidateManagementFixture();
    $message = $session->messages()->create(['role' => 'candidate', 'content' => 'Saved historical answer']);
    $evaluation = $session->evaluation()->create(['model' => 'test-model', 'result' => ['criteria' => [], 'strengths' => [], 'gaps' => []]]);
    $beforeMessage = $message->fresh()->getAttributes();
    $beforeEvaluation = $evaluation->fresh()->getAttributes();
    $beforeSnapshot = $session->criteria_snapshot;
    $this->actingAs($owner)->post("/candidates/{$candidate->id}/archive")->assertRedirect();
    expect($candidate->fresh()->archived_at)->not->toBeNull()->and($session->fresh()->criteria_snapshot)->toBe($beforeSnapshot);
    $this->get("/positions/{$position->id}")->assertInertia(fn (Assert $page) => $page->has('applications', 0)->where('archivedCandidateCount', 1)->where('candidateFilter', 'active'));
    $this->get("/positions/{$position->id}?candidates=archived")->assertInertia(fn (Assert $page) => $page->has('applications', 1)->where('applications.0.id', $candidate->id)
        ->where('candidateFilter', 'archived')->where('applications.0.can_delete', false)->where('applications.0.interview.id', $session->id));
    $this->get("/candidate/interviews/$token")->assertNotFound();
    $this->post("/candidate/interviews/$token", ['operation' => 'resume'])->assertNotFound();
    $this->get("/interviews/{$session->id}")->assertInertia(fn (Assert $page) => $page->has('interview.messages', 1)->has('interview.evaluation')->where('canRegenerateLink', false));
    $this->post("/interviews/{$session->id}/regenerate-link", ['link_version' => $session->fresh()->linkVersion()])->assertSessionHasErrors('link_version');
    expect($message->fresh()->getAttributes())->toBe($beforeMessage)->and($evaluation->fresh()->getAttributes())->toBe($beforeEvaluation);
    $archivedState = $session->fresh()->getAttributes();
    $this->post("/candidates/{$candidate->id}/archive")->assertRedirect();
    expect($session->fresh()->getAttributes())->toBe($archivedState);
});

test('restore requires a new interview link and preserves started progress while duplicate restores are harmless', function () {
    [$owner, $position, $candidate, $session, $token] = candidateManagementFixture();
    $session->messages()->create(['role' => 'assistant', 'content' => 'Main question']);
    $session->messages()->create(['role' => 'candidate', 'content' => 'Saved answer', 'submission_id' => (string) Str::uuid()]);
    $followup = $session->messages()->create(['role' => 'assistant', 'content' => 'Current followup']);
    $session->update(['follow_up_used' => true]);
    $beforeMessages = $session->messages()->get()->map->getAttributes()->all();
    $this->actingAs($owner)->post("/candidates/{$candidate->id}/archive")->assertRedirect();
    $this->post("/candidates/{$candidate->id}/restore")->assertRedirect(route('positions.show', $position).'#candidates');
    expect($candidate->fresh()->archived_at)->toBeNull()->and($session->fresh()->expires_at->isFuture())->toBeFalse();
    $this->get("/candidate/interviews/$token")->assertNotFound();
    [$newSession, $newToken] = app(RegenerateInterviewLink::class)->handle($owner, $session, $session->fresh()->linkVersion());
    $this->post("/candidates/{$candidate->id}/restore")->assertRedirect();
    $this->get("/candidate/interviews/$newToken")->assertInertia(fn (Assert $page) => $page->where('interview.current_question.id', $followup->id)->where('interview.question_number', 2));
    $this->get("/positions/{$position->id}")->assertInertia(fn (Assert $page) => $page->has('applications', 1));
    expect($newSession->id)->toBe($session->id)->and($session->fresh()->follow_up_used)->toBeTrue()
        ->and($session->messages()->get()->map->getAttributes()->all())->toBe($beforeMessages);
    Http::assertNothingSent();
});

test('legacy archived state with a still valid hash is denied and restore never revives that hash', function () {
    [$owner, , $candidate, $session, $token] = candidateManagementFixture();
    $candidate->update(['archived_at' => now()]);
    $this->get("/candidate/interviews/$token")->assertNotFound();
    $this->post("/candidate/interviews/$token", ['operation' => 'resume'])->assertNotFound();
    try {
        app(AdvanceInterview::class)->handle($session, 'resume');
        $this->fail('Archived candidate access must be checked under the interview lock.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);
    }
    $this->actingAs($owner)->post("/candidates/{$candidate->id}/restore")->assertRedirect();
    $this->get("/candidate/interviews/$token")->assertNotFound();
});

test('archive revokes a candidate request that resolved the private token before archiving', function () {
    [$owner, , $candidate, $session] = candidateManagementFixture();
    $resolvedBeforeArchive = $session->fresh();
    app(ManageCandidate::class)->archive($owner, $candidate);
    try {
        app(AdvanceInterview::class)->handle($resolvedBeforeArchive, 'resume');
        $this->fail('Revoked access must not start a question.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);
    }
    expect($session->messages()->count())->toBe(0);
});

test('unused fictional candidate deletion requires confirmation and deletes only its empty interview', function () {
    [$owner, , $candidate, $session, $token] = candidateManagementFixture();
    $this->actingAs($owner)->delete("/candidates/{$candidate->id}")->assertSessionHasErrors('confirm_delete');
    $this->delete("/candidates/{$candidate->id}", ['confirm_delete' => false])->assertSessionHasErrors('confirm_delete');
    expect($candidate->fresh()->canDeleteForDemo())->toBeTrue();
    $this->delete("/candidates/{$candidate->id}", ['confirm_delete' => true])->assertRedirect();
    expect(CandidateApplication::find($candidate->id))->toBeNull()->and(InterviewSession::find($session->id))->toBeNull();
    $this->get("/candidate/interviews/$token")->assertNotFound();
});

test('deletion rejects non fictional or started or dependent interviews', function (string $reason) {
    [$owner, , $candidate, $session] = candidateManagementFixture();
    if ($reason === 'real') {
        $candidate->update(['fictional' => false]);
    }
    if ($reason === 'assistant') {
        $session->messages()->create(['role' => 'assistant', 'content' => 'First question']);
    }
    if ($reason === 'answer') {
        $session->messages()->create(['role' => 'candidate', 'content' => 'Saved answer']);
    }
    if ($reason === 'evaluation') {
        $session->evaluation()->create(['model' => 'test', 'result' => []]);
    }
    if ($reason === 'completed') {
        $session->update(['status' => 'completed', 'completed_at' => now()]);
    }
    if ($reason === 'completed timestamp') {
        $session->update(['completed_at' => now()]);
    }
    if ($reason === 'progress') {
        $session->update(['question_index' => 1]);
    }
    if ($reason === 'followup') {
        $session->update(['follow_up_used' => true]);
    }
    if ($reason === 'processing') {
        $session->update(['processing_id' => (string) Str::uuid(), 'processing_started_at' => now()]);
    }
    if ($reason === 'processing timestamp') {
        $session->update(['processing_started_at' => now()->subMinutes(2)]);
    }
    $before = $session->fresh()->getAttributes();
    $this->actingAs($owner)->delete("/candidates/{$candidate->id}", ['confirm_delete' => true])->assertSessionHasErrors('candidate');
    expect($candidate->fresh())->not->toBeNull()->and($session->fresh()->getAttributes())->toBe($before);
})->with(['real', 'assistant', 'answer', 'evaluation', 'completed', 'completed timestamp', 'progress', 'followup', 'processing', 'processing timestamp']);

test('restrictive foreign keys prevent cascade deletion of interview evidence', function (string $evidence) {
    [, , $candidate, $session] = candidateManagementFixture();
    if ($evidence === 'message') {
        $session->messages()->create(['role' => 'candidate', 'content' => 'History']);
    } else {
        $session->evaluation()->create(['model' => 'test', 'result' => []]);
    }
    expect(fn () => DB::transaction(fn () => $session->delete()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $candidate->delete()))->toThrow(QueryException::class);
    expect($session->fresh())->not->toBeNull();
    expect($evidence === 'message' ? $session->messages()->count() : $session->evaluation()->count())->toBe(1);
})->with(['message', 'evaluation']);

test('unknown dependent recruitment records prevent deletion and roll back the empty interview removal', function () {
    [$owner, , $candidate, $session] = candidateManagementFixture();
    Schema::create('candidate_test_dependencies', function (Blueprint $table) {
        $table->id();
        $table->foreignId('candidate_application_id')->constrained('candidate_applications')->restrictOnDelete();
    });
    DB::table('candidate_test_dependencies')->insert(['candidate_application_id' => $candidate->id]);
    $this->actingAs($owner)->delete("/candidates/{$candidate->id}", ['confirm_delete' => true])->assertSessionHasErrors('candidate');
    expect($candidate->fresh())->not->toBeNull()->and($session->fresh())->not->toBeNull();
});

test('candidate mutations enforce authentication ownership verification and csrf', function () {
    [$owner, , $candidate] = candidateManagementFixture();
    $operations = [['patch', "/candidates/{$candidate->id}", ['name' => 'Edited']], ['post', "/candidates/{$candidate->id}/archive", []],
        ['post', "/candidates/{$candidate->id}/restore", []], ['delete', "/candidates/{$candidate->id}", ['confirm_delete' => true]]];
    foreach ($operations as [$method, $url, $data]) {
        $this->{$method}($url, $data)->assertRedirect('/login');
    }
    $this->actingAs(User::factory()->create());
    foreach ($operations as [$method, $url, $data]) {
        $this->{$method}($url, $data)->assertForbidden();
    }
    $owner->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($owner);
    foreach ($operations as [$method, $url, $data]) {
        $this->{$method}($url, $data)->assertRedirect(route('verification.notice'));
    }
    $owner->forceFill(['email_verified_at' => now()])->save();
    $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    foreach ($operations as [$method, $url, $data]) {
        $this->{$method}($url, $data)->assertStatus(419);
    }
    expect($candidate->fresh()->name)->toBe('Fictional Candidate')->and($candidate->fresh()->archived_at)->toBeNull();
});

test('completed candidates can be archived and restored without losing evidence or reopening candidate access', function () {
    [$owner, , $candidate, $session, $token] = candidateManagementFixture();
    $session->messages()->create(['role' => 'candidate', 'content' => 'Completed interview answer']);
    $session->evaluation()->create(['model' => 'historical-model', 'result' => []]);
    $session->update(['status' => 'completed', 'completed_at' => now()]);
    $completedAt = $session->fresh()->completed_at;
    $messages = $session->messages()->get()->map->getAttributes()->all();
    $evaluation = $session->evaluation->getAttributes();
    $this->actingAs($owner)->post("/candidates/{$candidate->id}/archive")->assertRedirect();
    $this->post("/candidates/{$candidate->id}/restore")->assertRedirect();
    $this->get("/candidate/interviews/$token")->assertNotFound();
    $this->get("/interviews/{$session->id}")->assertInertia(fn (Assert $page) => $page->where('canRegenerateLink', false)->has('interview.messages', 1)->where('interview.evaluation.model', 'historical-model'));
    $this->post("/interviews/{$session->id}/regenerate-link", ['link_version' => $session->fresh()->linkVersion()])->assertSessionHasErrors('link_version');
    $this->delete("/candidates/{$candidate->id}", ['confirm_delete' => true])->assertSessionHasErrors('candidate');
    expect($session->fresh()->status)->toBe('completed')->and($session->fresh()->completed_at->equalTo($completedAt))->toBeTrue()
        ->and($session->messages()->get()->map->getAttributes()->all())->toBe($messages)->and($session->evaluation->fresh()->getAttributes())->toBe($evaluation);
});
