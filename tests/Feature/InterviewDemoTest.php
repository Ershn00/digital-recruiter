<?php

use App\Domain\Interviews\Actions\AdvanceInterview;
use App\Domain\Interviews\Actions\RegenerateInterviewLink;
use App\Domain\Interviews\Actions\StartInterview;
use App\Domain\Interviews\Services\OpenAiInterview;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CandidateApplication;
use App\Models\CandidateEvaluation;
use App\Models\InterviewSession;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['services.openai.key' => 'test-key', 'services.openai.model' => 'gpt-4.1-mini']);
});

function demoPosition(?User $owner = null): JobPosition
{
    $owner ??= User::factory()->create();
    $position = new JobPosition([
        'title' => 'Senior Laravel Developer', 'description' => 'Laravel servisleri geliştirin.',
        'location' => 'İstanbul', 'employment_type' => 'full_time', 'interview_language' => 'tr', 'status' => 'active',
        'requirements' => [['id' => (string) Str::uuid(), 'text' => 'Laravel deneyimi', 'category' => 'essential', 'display_order' => 0]],
        'screening_topics' => [['id' => (string) Str::uuid(), 'text' => 'Laravel projenizi anlatın.', 'requirement_id' => null, 'required' => true, 'display_order' => 0]],
    ]);
    $position->created_by = $owner->id;
    $position->save();

    return $position;
}

function demoInterview(?JobPosition $position = null): array
{
    return app(StartInterview::class)->handle($position ?? demoPosition(), 'Kurgusal Deniz');
}

function aiResult(array $data): array
{
    return ['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]];
}

function answerPayload(int $messageId, string $text = 'Laravel ile kuyruk tabanlı sipariş servisi geliştirdim.'): array
{
    return ['operation' => 'answer', 'reply_to' => $messageId, 'submission_id' => (string) Str::uuid(), 'content' => $text];
}

function evaluationResult(InterviewSession $session, int $messageId, string $quote): array
{
    return ['criteria' => [['criterion_id' => $session->criteria_snapshot['requirements'][0]['id'], 'state' => 'supported', 'rationale' => 'Somut Laravel örneği verdi.', 'evidence' => [['message_id' => $messageId, 'quote' => $quote]]]], 'strengths' => ['Laravel proje deneyimi'], 'gaps' => []];
}

test('Turkish and English positions can be saved through validation', function (string $language) {
    $owner = User::factory()->create();
    $position = demoPosition($owner);
    $data = array_intersect_key($position->toArray(), array_flip(['title', 'description', 'location', 'employment_type', 'status', 'requirements', 'screening_topics']));
    $this->actingAs($owner)->post('/positions', [...$data, 'interview_language' => $language])->assertRedirect();
    expect(JobPosition::latest('id')->first()->interview_language->value)->toBe($language);
})->with(['tr', 'en']);

test('recruiter creates fictional application and immutable criteria snapshot with hashed access token', function () {
    $position = demoPosition();
    $this->actingAs($position->creator)->post("/positions/{$position->id}/interviews", ['name' => 'Kurgusal Deniz'])
        ->assertInertia(fn (Assert $page) => $page->component('interviews/created')->has('candidateUrl'));
    $session = InterviewSession::firstOrFail();
    expect($session->application->fictional)->toBeTrue()->and($session->access_token_hash)->toHaveLength(64)
        ->and($session->expires_at->isFuture())->toBeTrue();
    $snapshot = $session->criteria_snapshot;
    $position->update(['requirements' => []]);
    expect($session->fresh()->criteria_snapshot)->toBe($snapshot);
    expect($session->toArray())->not->toHaveKey('access_token_hash');
});

test('token is non guessable hashed expiring and grants only its interview', function () {
    [$one, $token] = demoInterview();
    [$two, $otherToken] = demoInterview();
    expect($token)->toHaveLength(64)->and($one->access_token_hash)->toBe(hash('sha256', $token))->not->toBe($token);
    $this->get("/candidate/interviews/$token")->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page->where('interview.id', $one->id)->missing('interview.evaluation')->missing('interview.access_token_hash'));
    $this->get('/candidate/interviews/'.str_repeat('0', 64))->assertNotFound();
    $this->get('/candidate/interviews/123')->assertNotFound();
    Http::fake(['api.openai.com/*' => Http::response(aiResult(['question' => 'Laravel deneyiminiz nedir?', 'follow_up' => false]))]);
    $this->post("/candidate/interviews/$token", ['operation' => 'resume', 'interview_session_id' => $two->id])->assertRedirect();
    expect($one->messages()->count())->toBe(1)->and($two->messages()->count())->toBe(0);
    $one->update(['expires_at' => now()->subSecond()]);
    $this->get("/candidate/interviews/$token")->assertNotFound();
    $this->post("/candidate/interviews/$token", ['operation' => 'complete'])->assertNotFound();
});

test('other recruiters and guests cannot create inspect or evaluate interviews', function () {
    $position = demoPosition();
    [$session] = demoInterview($position);
    $this->get("/interviews/{$session->id}")->assertRedirect('/login');
    $this->post("/interviews/{$session->id}/evaluate")->assertRedirect('/login');
    $this->actingAs(User::factory()->create());
    $this->post("/positions/{$position->id}/interviews", ['name' => 'Other'])->assertForbidden();
    $this->get("/interviews/{$session->id}")->assertForbidden();
    $this->post("/interviews/{$session->id}/evaluate")->assertForbidden();
    expect(CandidateApplication::count())->toBe(1);
});

test('draft archived or empty criteria positions cannot start an interview', function () {
    $position = demoPosition();
    $this->actingAs($position->creator);
    foreach (['draft', 'archived'] as $status) {
        $position->update(['status' => $status]);
        $this->post("/positions/{$position->id}/interviews", ['name' => 'Demo'])->assertSessionHasErrors('name');
    }
    $position->update(['status' => 'active', 'requirements' => []]);
    $this->post("/positions/{$position->id}/interviews", ['name' => 'Demo'])->assertSessionHasErrors('name');
    expect(InterviewSession::count())->toBe(0);
});

test('real request contract persists questions answers and bounds followups before completion', function () {
    [$session, $token] = demoInterview();
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(aiResult(['question' => 'Laravel projenizi anlatır mısınız?', 'follow_up' => false]))
        ->push(aiResult(['question' => 'Kuyruk hatalarını nasıl yönettiniz?', 'follow_up' => true]))]);
    $url = "/candidate/interviews/$token";
    $this->post($url, ['operation' => 'resume'])->assertRedirect();
    $question = $session->messages()->first();
    $payload = answerPayload($question->id);
    $this->post($url, $payload)->assertRedirect();
    $this->post($url, $payload)->assertRedirect();
    expect($session->messages()->count())->toBe(3)->and($session->fresh()->follow_up_used)->toBeTrue();
    $followup = $session->messages()->reorder()->latest('id')->first();
    $this->post($url, answerPayload($followup->id, 'Başarısız işleri yeniden denedim.'))->assertRedirect();
    expect($session->fresh()->status)->toBe('completed')->and($session->messages()->count())->toBe(4);
    $this->post($url, answerPayload($followup->id))->assertRedirect();
    expect($session->messages()->count())->toBe(4);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer test-key') && $request['store'] === false
        && $request['text']['format']['type'] === 'json_schema'
        && str_contains($request['input'], 'tr'));
});

test('timeouts preserve saved answers and retries do not duplicate or advance twice', function () {
    [$session, $token] = demoInterview();
    $question = $session->messages()->create(['role' => 'assistant', 'content' => 'Bir Laravel projesi anlatın.']);
    $payload = answerPayload($question->id);
    Http::fake(['api.openai.com/*' => Http::sequence()->pushFailedConnection()->push(aiResult(['question' => 'Bir hata örneği verir misiniz?', 'follow_up' => true]))]);
    $this->post("/candidate/interviews/$token", $payload)->assertSessionHasErrors('interview');
    expect($session->messages()->where('role', 'candidate')->count())->toBe(1)
        ->and($session->fresh()->processing_id)->toBeNull()->and($session->fresh()->follow_up_used)->toBeTrue();
    $this->post("/candidate/interviews/$token", $payload)->assertRedirect();
    expect($session->messages()->count())->toBe(3)->and($session->fresh()->question_index)->toBe(0);
});

test('stale tabs duplicate IDs and concurrent requests cannot overwrite saved answers', function () {
    [$session, $token] = demoInterview();
    $question = $session->messages()->create(['role' => 'assistant', 'content' => 'Laravel?']);
    Http::fake(['api.openai.com/*' => Http::response(aiResult(['question' => 'Örnek?', 'follow_up' => true]))]);
    $payload = answerPayload($question->id);
    $this->post("/candidate/interviews/$token", $payload)->assertRedirect();
    $this->post("/candidate/interviews/$token", answerPayload($question->id, 'Stale answer'))->assertSessionHasErrors('interview');
    $this->post("/candidate/interviews/$token", [...$payload, 'content' => 'Changed'])->assertSessionHasErrors('interview');
    $session->update(['processing_id' => (string) Str::uuid(), 'processing_started_at' => now()]);
    $this->post("/candidate/interviews/$token", ['operation' => 'resume'])->assertSessionHasErrors('interview');
    expect($session->messages()->where('role', 'candidate')->count())->toBe(1);
});

test('abandoned processing leases can recover without losing transcript', function () {
    [$session, $token] = demoInterview();
    $session->messages()->create(['role' => 'candidate', 'content' => 'Saved answer']);
    $session->update(['processing_id' => (string) Str::uuid(), 'processing_started_at' => now()->subMinutes(2)]);
    Http::fake(['api.openai.com/*' => Http::response(aiResult(['question' => 'Devam edelim mi?', 'follow_up' => false]))]);
    $this->post("/candidate/interviews/$token", ['operation' => 'resume'])->assertRedirect();
    expect($session->messages()->count())->toBe(2)->and($session->fresh()->processing_id)->toBeNull();
});

test('automatic completion freezes transcript and evaluation is recruiter only and idempotent', function () {
    $position = demoPosition();
    [$session, $token] = demoInterview($position);
    $session->messages()->create(['role' => 'assistant', 'content' => 'Laravel deneyiminiz?']);
    $answer = $session->messages()->create(['role' => 'candidate', 'content' => 'Laravel ile servis geliştirdim.']);
    $this->actingAs($position->creator)->post("/interviews/{$session->id}/evaluate")->assertSessionHasErrors('interview');
    $session->messages()->create(['role' => 'assistant', 'content' => 'Bir ayrıntı daha?']);
    $session->update(['follow_up_used' => true]);
    $finalPayload = answerPayload($session->messages()->reorder()->latest('id')->first()->id, $answer->content);
    $this->post("/candidate/interviews/$token", $finalPayload)->assertRedirect();
    $this->post("/candidate/interviews/$token", $finalPayload)->assertRedirect();
    Http::fake(['api.openai.com/*' => Http::response(aiResult(evaluationResult($session, $answer->id, 'Laravel ile servis geliştirdim.')))]);
    $this->post("/interviews/{$session->id}/evaluate")->assertRedirect();
    $this->post("/interviews/{$session->id}/evaluate")->assertRedirect();
    expect(CandidateEvaluation::count())->toBe(1)->and($session->fresh()->completed_at)->not->toBeNull();
    Http::assertSentCount(1);
    $this->get("/interviews/{$session->id}")->assertInertia(fn (Assert $page) => $page->has('interview.messages', 4)->where('interview.evaluation.result.criteria.0.evidence.0.message_id', $answer->id));
});

test('evaluation rejects invented quotes foreign or assistant references and missing criterion coverage', function (string $invalid) {
    $position = demoPosition();
    [$session] = demoInterview($position);
    [$other] = demoInterview();
    $assistant = $session->messages()->create(['role' => 'assistant', 'content' => 'Laravel ile servis geliştirdim.']);
    $answer = $session->messages()->create(['role' => 'candidate', 'content' => 'Laravel ile servis geliştirdim.']);
    $foreign = $other->messages()->create(['role' => 'candidate', 'content' => $answer->content]);
    $session->update(['status' => 'completed', 'completed_at' => now()]);
    $result = evaluationResult($session, $answer->id, $answer->content);
    if ($invalid === 'punctuation') {
        $result['criteria'][0]['evidence'][0]['quote'] = rtrim($answer->content, '.').'!';
    }
    if ($invalid === 'quote') {
        $result['criteria'][0]['evidence'][0]['quote'] = 'Invented claim';
    }
    if ($invalid === 'foreign') {
        $result['criteria'][0]['evidence'][0]['message_id'] = $foreign->id;
    }
    if ($invalid === 'assistant') {
        $result['criteria'][0]['evidence'][0]['message_id'] = $assistant->id;
    }
    if ($invalid === 'criterion') {
        $result['criteria'][0]['criterion_id'] = (string) Str::uuid();
    }
    if ($invalid === 'empty') {
        $result['criteria'][0]['evidence'] = [];
    }
    Http::fake(['api.openai.com/*' => Http::response(aiResult($result))]);
    $this->actingAs($position->creator)->post("/interviews/{$session->id}/evaluate")->assertSessionHasErrors('interview');
    expect(CandidateEvaluation::count())->toBe(0)->and($session->fresh()->processing_id)->toBeNull()->and($session->messages()->count())->toBe(2);
})->with(['quote', 'punctuation', 'foreign', 'assistant', 'criterion', 'empty']);

test('legacy completed interviews without answers yield insufficient evidence and evaluation failures can retry', function () {
    $position = demoPosition();
    [$session, $token] = demoInterview($position);
    $session->update(['status' => 'completed', 'completed_at' => now()]);
    $this->actingAs($position->creator);
    $result = ['criteria' => [['criterion_id' => $session->criteria_snapshot['requirements'][0]['id'], 'state' => 'insufficient_evidence', 'rationale' => 'Yanıt yok.', 'evidence' => []]], 'strengths' => [], 'gaps' => []];
    Http::fake(['api.openai.com/*' => Http::sequence()->push([], 503)->push(aiResult($result))]);
    $this->post("/interviews/{$session->id}/evaluate")->assertSessionHasErrors('interview');
    $this->post("/interviews/{$session->id}/evaluate")->assertRedirect();
    expect($session->evaluation->result['criteria'][0]['state'])->toBe('insufficient_evidence');
});

test('multiple topics advance in saved order with exactly one followup per topic', function () {
    $position = demoPosition();
    $topics = $position->screening_topics;
    $topics[] = ['id' => (string) Str::uuid(), 'text' => 'PostgreSQL sorgusunu nasıl iyileştirdiniz?', 'requirement_id' => null, 'required' => true, 'display_order' => 1];
    $position->update(['screening_topics' => $topics]);
    [$session, $token] = demoInterview($position);
    Http::fake(['api.openai.com/*' => Http::response(aiResult(['question' => 'İş deneyiminizden bir örnek?', 'follow_up' => false]))]);
    $this->post("/candidate/interviews/$token", ['operation' => 'resume']);
    for ($turn = 0; $turn < 4; $turn++) {
        $last = $session->messages()->reorder()->latest('id')->first();
        $this->post("/candidate/interviews/$token", answerPayload($last->id))->assertRedirect();
    }
    expect($session->fresh()->status)->toBe('completed')->and($session->fresh()->question_index)->toBe(2)
        ->and($session->messages()->where('role', 'assistant')->count())->toBe(4)
        ->and($session->messages()->where('role', 'candidate')->count())->toBe(4);
    $inputs = Http::recorded()->map(fn ($pair) => json_decode($pair[0]['input'], true))->all();
    expect(array_column(array_column($inputs, 'current_topic'), 'id'))->toBe([$topics[0]['id'], $topics[0]['id'], $topics[1]['id'], $topics[1]['id']]);
    expect(array_column($inputs, 'follow_up'))->toBe([false, true, false, true]);
});

test('malformed incomplete or refused model responses do not persist questions', function (array $response) {
    [$session, $token] = demoInterview();
    Http::fake(['api.openai.com/*' => Http::response($response)]);
    $this->post("/candidate/interviews/$token", ['operation' => 'resume'])->assertSessionHasErrors('interview');
    expect($session->messages()->count())->toBe(0)->and($session->fresh()->processing_id)->toBeNull()
        ->and($session->fresh()->status)->toBe('active');
})->with([
    'incomplete' => [['status' => 'incomplete', 'output' => []]],
    'refusal' => [['status' => 'completed', 'output' => [['content' => [['type' => 'refusal', 'refusal' => 'No']]]]]],
    'invalid JSON' => [['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => '{broken']]]]]],
    'invalid fields' => [aiResult(['question' => '', 'follow_up' => false])],
]);

test('a superseded model worker cannot save a late question', function () {
    [$session, $token] = demoInterview();
    Http::fake(['api.openai.com/*' => function () use ($session) {
        $session->update(['processing_id' => (string) Str::uuid(), 'processing_started_at' => now()]);

        return Http::response(aiResult(['question' => 'Late response', 'follow_up' => false]));
    }]);
    $this->post("/candidate/interviews/$token", ['operation' => 'resume'])->assertRedirect();
    expect($session->messages()->count())->toBe(0);
});

test('missing OpenAI configuration fails safely without issuing a request', function () {
    [$session, $token] = demoInterview();
    config(['services.openai.key' => null]);
    $this->post("/candidate/interviews/$token", ['operation' => 'resume'])->assertSessionHasErrors('interview');
    expect($session->fresh()->processing_id)->toBeNull();
    Http::assertNothingSent();
});

test('completed candidate links select the dedicated localized screen on every visit and preserve recruiter evidence', function (string $language) {
    $position = demoPosition();
    $position->update(['interview_language' => $language]);
    [$session, $token] = demoInterview($position);
    $question = $session->messages()->create(['role' => 'assistant', 'content' => 'Laravel deneyiminiz?']);
    $answer = $session->messages()->create(['role' => 'candidate', 'content' => 'Laravel ile servis geliştirdim.']);
    $session->update(['status' => 'completed', 'completed_at' => now()]);
    $evaluation = $session->evaluation()->create(['result' => evaluationResult($session, $answer->id, $answer->content), 'model' => 'test-model']);
    $beforeSession = $session->fresh()->getAttributes();
    $beforeMessages = $session->messages()->get()->map->getAttributes()->all();
    $beforeEvaluation = $evaluation->fresh()->getAttributes();
    // The language/title must come from the interview snapshot, even after position edits.
    $position->update(['title' => 'Changed role', 'interview_language' => $language === 'tr' ? 'en' : 'tr']);

    for ($visit = 0; $visit < 2; $visit++) {
        $this->get("/candidate/interviews/$token")->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page->component('candidate/completed')
                ->where('interview.status', 'completed')
                ->where('interview.title', 'Senior Laravel Developer')
                ->where('interview.language', $language)
                ->missing('interview.messages')->missing('interview.evaluation')->missing('token'));
    }

    $this->actingAs($position->creator)->get("/interviews/{$session->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('interviews/show')
            ->has('interview.messages', 2)
            ->where('interview.messages.0.id', $question->id)
            ->where('interview.messages.1.content', $answer->content)
            ->where('interview.evaluation.id', $evaluation->id));
    expect($session->fresh()->getAttributes())->toBe($beforeSession)
        ->and($session->messages()->get()->map->getAttributes()->all())->toBe($beforeMessages)
        ->and($evaluation->fresh()->getAttributes())->toBe($beforeEvaluation);
    Http::assertNothingSent();
})->with(['tr', 'en']);

test('completed candidate links remain protected by token validation and expiration', function () {
    [$session, $token] = demoInterview();
    $session->update(['status' => 'completed', 'completed_at' => now()]);
    $session->update(['expires_at' => now()->subSecond()]);
    $this->get("/candidate/interviews/$token")->assertNotFound();
    $this->get('/candidate/interviews/'.str_repeat('0', 64))->assertNotFound();
    $this->get('/candidate/interviews/invalid')->assertNotFound();
    Http::assertNothingSent();
});

test('candidate presentation resumes one persisted question at a time including numbered followups', function (string $language) {
    $position = demoPosition();
    $topics = $position->screening_topics;
    $topics[] = ['id' => (string) Str::uuid(), 'text' => 'PostgreSQL?', 'requirement_id' => null, 'required' => true, 'display_order' => 1];
    $position->update(['interview_language' => $language, 'screening_topics' => $topics]);
    [$session, $token] = demoInterview($position);
    $url = "/candidate/interviews/$token";
    $this->get($url)->assertInertia(fn (Assert $page) => $page->component('candidate/interview')
        ->where('interview.started', false)->where('interview.current_question', null)
        ->where('interview.question_number', 0)->where('interview.language', $language)
        ->missing('interview.messages'));
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(aiResult(['question' => 'Main topic one', 'follow_up' => false]))
        ->push(aiResult(['question' => 'Followup topic one', 'follow_up' => true]))
        ->push(aiResult(['question' => 'Main topic two', 'follow_up' => false]))
        ->push(aiResult(['question' => 'Followup topic two', 'follow_up' => true]))]);
    $this->post($url, ['operation' => 'resume'])->assertRedirect();
    foreach (['Main topic one', 'Followup topic one', 'Main topic two', 'Followup topic two'] as $index => $questionText) {
        $question = $session->messages()->reorder()->latest('id')->first();
        $beforeMessages = $session->messages()->get()->map->getAttributes()->all();
        for ($visit = 0; $visit < 2; $visit++) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page->component('candidate/interview')
                ->where('interview.current_question.id', $question->id)
                ->where('interview.current_question.content', $questionText)
                ->where('interview.question_number', $index + 1)
                ->where('interview.completed_topics', intdiv($index, 2))
                ->where('interview.total_topics', 2)
                ->where('interview.answer_saved', false)->missing('interview.messages'));
        }
        expect($session->messages()->get()->map->getAttributes()->all())->toBe($beforeMessages);
        $payload = answerPayload($question->id, "Saved answer for question {$index}");
        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();
        $saved = $session->messages()->where('submission_id', $payload['submission_id'])->firstOrFail();
        expect($saved->content)->toBe($payload['content']);
        if ($index < 3) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page
                ->where('interview.last_submission_id', $payload['submission_id'])->missing('interview.messages'))
                ->assertDontSee($saved->content);
        }
    }
    $this->get($url)->assertInertia(fn (Assert $page) => $page->component('candidate/completed')->missing('interview.messages'));
    $this->actingAs($position->creator)->get("/interviews/{$session->id}")
        ->assertInertia(fn (Assert $page) => $page->component('interviews/show')->has('interview.messages', 8));
    expect($session->fresh()->status)->toBe('completed')->and($session->messages()->where('role', 'candidate')->count())->toBe(4);
    Http::assertSentCount(4);
})->with(['tr', 'en']);

test('failed question generation exposes only saved-answer recovery and resume does not replay or duplicate the answer', function () {
    [$session, $token] = demoInterview();
    $question = $session->messages()->create(['role' => 'assistant', 'content' => 'Previously answered question']);
    $payload = answerPayload($question->id, 'This candidate answer stays private to the recruiter transcript');
    $url = "/candidate/interviews/$token";
    Http::fake(['api.openai.com/*' => Http::sequence()->pushFailedConnection()
        ->push(aiResult(['question' => 'Current followup question', 'follow_up' => true]))]);
    $this->post($url, $payload)->assertSessionHasErrors('interview');
    $this->get($url)->assertInertia(fn (Assert $page) => $page->component('candidate/interview')
        ->where('interview.answer_saved', true)->where('interview.current_question', null)
        ->where('interview.question_number', 1)->where('interview.last_submission_id', $payload['submission_id'])
        ->missing('interview.messages'))->assertDontSee($question->content)->assertDontSee($payload['content']);
    $this->post($url, ['operation' => 'resume'])->assertRedirect();
    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('interview.current_question.content', 'Current followup question')
        ->where('interview.question_number', 2)->where('interview.answer_saved', false));
    expect($session->messages()->count())->toBe(3)->and($session->messages()->where('role', 'candidate')->count())->toBe(1);
});

test('manual completion is rejected before and during required screening topics', function () {
    [$session, $token] = demoInterview();
    $url = "/candidate/interviews/$token";
    $before = $session->fresh()->getAttributes();
    $this->post($url, ['operation' => 'complete'])->assertSessionHasErrors('operation');
    expect($session->fresh()->getAttributes())->toBe($before);
    Http::fake(['api.openai.com/*' => Http::response(aiResult(['question' => 'Required followup', 'follow_up' => true]))]);
    $question = $session->messages()->create(['role' => 'assistant', 'content' => 'Required main question']);
    $this->post($url, answerPayload($question->id))->assertRedirect();
    expect($session->fresh()->status)->toBe('active')->and($session->fresh()->question_index)->toBe(0);
    $this->post($url, ['operation' => 'complete'])->assertSessionHasErrors('operation');
    expect($session->fresh()->status)->toBe('active')->and($session->fresh()->completed_at)->toBeNull();
    expect(fn () => app(AdvanceInterview::class)->handle($session, 'complete'))
        ->toThrow(ValidationException::class);
});

test('final answer commits before automatic completion and retries refresh and competing requests preserve it exactly once', function () {
    [$session, $token] = demoInterview();
    $url = "/candidate/interviews/$token";
    $question = $session->messages()->create(['role' => 'assistant', 'content' => 'Main question']);
    Http::fake(['api.openai.com/*' => Http::response(aiResult(['question' => 'Final permitted followup', 'follow_up' => true]))]);
    $this->post($url, answerPayload($question->id, 'Main answer'))->assertRedirect();
    $followup = $session->messages()->reorder()->latest('id')->first();
    $payload = answerPayload($followup->id, 'Final saved answer');
    $session->update(['processing_id' => (string) Str::uuid(), 'processing_started_at' => now()]);
    $this->post($url, $payload)->assertSessionHasErrors('interview');
    expect($session->messages()->where('submission_id', $payload['submission_id'])->exists())->toBeFalse();
    $session->update(['processing_id' => null, 'processing_started_at' => null]);
    $this->post($url, $payload)->assertRedirect();
    $final = $session->messages()->where('submission_id', $payload['submission_id'])->firstOrFail();
    expect($final->content)->toBe('Final saved answer')->and($session->fresh()->status)->toBe('completed');
    $beforeSession = $session->fresh()->getAttributes();
    $beforeMessages = $session->messages()->get()->map->getAttributes()->all();
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->post($url, $payload)->assertRedirect();
        $this->post($url, ['operation' => 'resume'])->assertRedirect();
        $this->get($url)->assertInertia(fn (Assert $page) => $page->component('candidate/completed')->where('interview.last_submission_id', $payload['submission_id']));
    }
    expect($session->fresh()->getAttributes())->toBe($beforeSession)
        ->and($session->messages()->get()->map->getAttributes()->all())->toBe($beforeMessages)
        ->and($session->messages()->where('submission_id', $payload['submission_id'])->count())->toBe(1);
    Http::assertSentCount(1);
});

test('private link confirmation preserves position context without persisting or recovering raw tokens', function () {
    $position = demoPosition();
    $this->get("/positions/{$position->id}/interviews")->assertRedirect('/login');
    $response = $this->actingAs($position->creator)->post("/positions/{$position->id}/interviews", ['name' => 'Private Demo Candidate']);
    $candidateUrl = null;
    $response->assertHeader('Referrer-Policy', 'no-referrer')->assertInertia(function (Assert $page) use ($position, &$candidateUrl) {
        return $page->component('interviews/created')->where('position.id', $position->id)
            ->where('candidateName', 'Private Demo Candidate')->where('candidateUrl', function ($url) use (&$candidateUrl) {
                $candidateUrl = $url;

                return is_string($url);
            });
    });
    $token = basename($candidateUrl);
    $session = InterviewSession::firstOrFail();
    expect($session->access_token_hash)->toBe(hash('sha256', $token));
    expect(json_encode($session->getAttributes()))->not->toContain($token);
    expect(json_encode(session()->all()))->not->toContain($token);
    $this->get("/interviews/{$session->id}")->assertInertia(fn (Assert $page) => $page->component('interviews/show')
        ->where('interview.application.job_position_id', $position->id)->missing('candidateUrl')->missing('interview.access_token_hash'))
        ->assertDontSee($token);
    $this->get("/positions/{$position->id}/interviews")->assertRedirect(route('positions.show', $position).'#candidates');
    $this->get("/positions/{$position->id}")->assertInertia(fn (Assert $page) => $page->where('applications.0.interview.id', $session->id));
    expect(InterviewSession::count())->toBe(1);
    $this->actingAs(User::factory()->create())->get("/positions/{$position->id}/interviews")->assertForbidden();
});

test('candidate links use the related interview ID and render the correct authenticated Inertia detail', function () {
    $position = demoPosition();
    $position->applications()->create(['name' => 'Existing application without an interview', 'fictional' => true]);
    [$session] = demoInterview($position);
    $application = $session->application;
    expect($application->id)->not->toBe($session->id);
    $url = route('interviews.show', ['interview' => $session->id], false);
    $this->actingAs($position->creator)->get("/positions/{$position->id}")
        ->assertInertia(fn (Assert $page) => $page->where('applications', function ($applications) use ($application, $session, $url) {
            $row = collect($applications)->firstWhere('id', $application->id);

            return $row['interview_url'] === $url && $row['interview']['id'] === $session->id;
        }));
    $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create($url))])->assertOk()->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'interviews/show')
        ->assertJsonPath('props.interview.id', $session->id)
        ->assertJsonPath('props.interview.application.id', $application->id)
        ->assertJsonPath('props.interview.application.name', $application->name)
        ->assertJsonPath('props.position.id', $position->id)
        ->assertJsonPath('props.canRegenerateLink', true);
});

test('authorized regeneration invalidates the old token and preserves all existing interview data', function () {
    $this->freezeTime();
    $position = demoPosition();
    [$session, $oldToken] = demoInterview($position);
    $session->messages()->create(['role' => 'assistant', 'content' => 'Main question']);
    $answer = $session->messages()->create(['role' => 'candidate', 'content' => 'Saved answer', 'submission_id' => (string) Str::uuid()]);
    $followup = $session->messages()->create(['role' => 'assistant', 'content' => 'Current followup']);
    $session->update(['follow_up_used' => true]);
    $session->evaluation()->create(['model' => 'existing-model', 'result' => evaluationResult($session, $answer->id, $answer->content)]);
    $beforeSession = Arr::except($session->fresh()->getAttributes(), ['access_token_hash', 'expires_at', 'updated_at']);
    $beforeApplication = $session->application->getAttributes();
    $beforeMessages = $session->messages()->get()->map->getAttributes()->all();
    $beforeEvaluation = $session->evaluation->getAttributes();
    $newUrl = null;
    $response = $this->actingAs($position->creator)->post("/interviews/{$session->id}/regenerate-link", ['link_version' => $session->linkVersion()]);
    $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page->component('interviews/created')
            ->where('regenerated', true)->where('interviewId', $session->id)->where('position.id', $position->id)
            ->has('candidateUrl'));
    $newUrl = $response->viewData('page')['props']['candidateUrl'];
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $newToken = basename($newUrl);
    expect($newToken)->toHaveLength(64)->not->toBe($oldToken)
        ->and($session->fresh()->access_token_hash)->toBe(hash('sha256', $newToken))
        ->and($session->fresh()->expires_at->timestamp)->toBe(now()->addHours(24)->timestamp);
    expect(Arr::except($session->fresh()->getAttributes(), ['access_token_hash', 'expires_at', 'updated_at']))->toBe($beforeSession)
        ->and($session->application->fresh()->getAttributes())->toBe($beforeApplication)
        ->and($session->messages()->get()->map->getAttributes()->all())->toBe($beforeMessages)
        ->and($session->evaluation->fresh()->getAttributes())->toBe($beforeEvaluation);
    expect(json_encode($session->fresh()->getAttributes()))->not->toContain($newToken);
    expect(json_encode(session()->all()))->not->toContain($newToken);
    $this->get("/candidate/interviews/$oldToken")->assertNotFound();
    $this->post("/candidate/interviews/$oldToken", ['operation' => 'resume'])->assertNotFound();
    $this->get($newUrl)->assertInertia(fn (Assert $page) => $page->component('candidate/interview')
        ->where('interview.current_question.id', $followup->id)->where('interview.question_number', 2)
        ->where('interview.last_submission_id', $answer->submission_id));
    $this->post($newUrl, ['operation' => 'resume'])->assertRedirect();
    $this->get("/interviews/{$session->id}/regenerate-link")->assertRedirect(route('interviews.show', $session));
    $this->get("/interviews/{$session->id}")->assertInertia(fn (Assert $page) => $page->has('interview.messages', 3)
        ->where('interview.evaluation.model', 'existing-model')->missing('candidateUrl')->missing('interview.access_token_hash'))
        ->assertDontSee($newToken);
    expect(InterviewSession::count())->toBe(1)->and(CandidateApplication::count())->toBe(1)->and($session->messages()->count())->toBe(3);
    Http::assertNothingSent();
});

test('expired unfinished links can be replaced with a fresh 24 hour link', function () {
    $this->freezeTime();
    $position = demoPosition();
    [$session, $oldToken] = demoInterview($position);
    $session->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($position->creator)->get("/interviews/{$session->id}")
        ->assertInertia(fn (Assert $page) => $page->where('linkExpired', true)->where('canRegenerateLink', true));
    $this->get("/candidate/interviews/$oldToken")->assertNotFound();
    [$rotated, $newToken] = app(RegenerateInterviewLink::class)->handle($position->creator, $session, $session->linkVersion());
    $this->get("/candidate/interviews/$newToken")->assertOk();
    $this->travel(24)->hours();
    $this->get("/candidate/interviews/$newToken")->assertNotFound();
    $this->post("/candidate/interviews/$newToken", ['operation' => 'resume'])->assertNotFound();
    expect($rotated->id)->toBe($session->id);
});

test('completed interviews cannot regenerate links even from a stale active model', function () {
    $position = demoPosition();
    [$session] = demoInterview($position);
    $stale = $session->fresh();
    $session->update(['status' => 'completed', 'completed_at' => now()]);
    $before = $session->fresh()->getAttributes();
    $this->actingAs($position->creator)->post("/interviews/{$session->id}/regenerate-link", ['link_version' => $session->linkVersion()])->assertSessionHasErrors('link_version');
    $this->get("/interviews/{$session->id}")->assertInertia(fn (Assert $page) => $page->where('canRegenerateLink', false));
    expect(fn () => app(RegenerateInterviewLink::class)->handle($position->creator, $stale, $stale->linkVersion()))->toThrow(ValidationException::class);
    expect($session->fresh()->getAttributes())->toBe($before);
});

test('competing regeneration requests using the same version cannot invalidate the winning link', function () {
    $position = demoPosition();
    [$session, $oldToken] = demoInterview($position);
    $version = $session->linkVersion();
    $stale = $session->fresh();
    [$rotated, $winningToken] = app(RegenerateInterviewLink::class)->handle($position->creator, $session, $version);
    $before = $rotated->fresh()->getAttributes();
    $this->actingAs($position->creator)->post("/interviews/{$session->id}/regenerate-link", ['link_version' => $version])->assertSessionHasErrors('link_version');
    expect(fn () => app(RegenerateInterviewLink::class)->handle($position->creator, $stale, $version))->toThrow(ValidationException::class);
    expect($session->fresh()->getAttributes())->toBe($before);
    $this->get("/candidate/interviews/$winningToken")->assertOk();
    $this->get("/candidate/interviews/$oldToken")->assertNotFound();
    expect(InterviewSession::count())->toBe(1)->and(CandidateApplication::count())->toBe(1);
});

test('link rotation does not reset in flight AI work and rejects a candidate request resolved before rotation', function () {
    $position = demoPosition();
    [$session] = demoInterview($position);
    $question = $session->messages()->create(['role' => 'assistant', 'content' => 'Unanswered question']);
    $session->update(['processing_id' => (string) Str::uuid(), 'processing_started_at' => now()]);
    $resolvedBeforeRotation = $session->fresh();
    app(RegenerateInterviewLink::class)->handle($position->creator, $session, $session->linkVersion());
    expect($session->fresh()->processing_id)->toBe($resolvedBeforeRotation->processing_id)
        ->and($session->fresh()->processing_started_at->equalTo($resolvedBeforeRotation->processing_started_at))->toBeTrue();
    $session->update(['processing_id' => null, 'processing_started_at' => null]);
    try {
        app(AdvanceInterview::class)->handle($resolvedBeforeRotation, 'answer', answerPayload($question->id));
        $this->fail('A revoked token must not be accepted after acquiring the session lock.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);
    }
    expect($session->messages()->count())->toBe(1);
    Http::assertNothingSent();
});

test('regeneration requires verified recruiter ownership and a valid version', function () {
    $position = demoPosition();
    [$session] = demoInterview($position);
    $url = "/interviews/{$session->id}/regenerate-link";
    $before = $session->fresh()->getAttributes();
    $this->post($url, ['link_version' => $session->linkVersion()])->assertRedirect('/login');
    $this->get($url)->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->post($url, ['link_version' => $session->linkVersion()])->assertForbidden();
    $this->get($url)->assertForbidden();
    $position->creator->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($position->creator)->post($url, ['link_version' => $session->linkVersion()])->assertRedirect(route('verification.notice'));
    $position->creator->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($position->creator)->post($url, [])->assertSessionHasErrors('link_version');
    $this->post($url, ['link_version' => 'invalid'])->assertSessionHasErrors('link_version');
    expect($session->fresh()->getAttributes())->toBe($before);
});

test('regeneration is protected by the existing web CSRF middleware', function () {
    $position = demoPosition();
    [$session] = demoInterview($position);
    $this->app->instance(PreventRequestForgery::class,
        new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    $before = $session->fresh()->getAttributes();
    $this->actingAs($position->creator)->post("/interviews/{$session->id}/regenerate-link", ['link_version' => $session->linkVersion()])->assertStatus(419);
    expect($session->fresh()->getAttributes())->toBe($before);
});

test('evaluation constrains all snapshot criteria and preserves persisted evidence IDs across a coverage failure and retry', function () {
    $position = demoPosition();
    [$other] = demoInterview();
    for ($i = 0; $i < 16; $i++) {
        $other->messages()->create(['role' => 'assistant', 'content' => 'Unrelated question']);
    }
    $requirements = [];
    for ($i = 0; $i < 5; $i++) {
        $requirements[] = [...$position->requirements[0], 'id' => (string) Str::uuid(), 'display_order' => $i];
    }
    $position->update(['requirements' => $requirements]);
    [$session] = demoInterview($position);
    $session->messages()->create(['role' => 'assistant', 'content' => 'Question']);
    $answer = $session->messages()->create(['role' => 'candidate', 'content' => 'Exact saved answer.']);
    $session->update(['status' => 'completed', 'completed_at' => now()]);
    $before = $session->messages()->get()->toArray();
    $valid = evaluationResult($session, $answer->id, $answer->content);
    $valid['criteria'] = array_map(fn ($requirement) => [
        ...$valid['criteria'][0], 'criterion_id' => $requirement['id'],
    ], $requirements);
    $incomplete = $valid;
    array_pop($incomplete['criteria']);
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::sequence()->push(aiResult($incomplete))->push(aiResult($valid))]);
    $this->actingAs($position->creator)->post("/interviews/{$session->id}/evaluate")->assertSessionHasErrors('interview');
    expect($session->fresh()->processing_id)->toBeNull()
        ->and($session->evaluation()->exists())->toBeFalse()
        ->and($session->messages()->get()->toArray())->toBe($before);
    Log::shouldHaveReceived('warning')->once()->with('Interview evaluation failed.', [
        'interview_id' => $session->id, 'stage' => 'generation', 'category' => 'schema_validation',
        'http_status' => null, 'validation_rules' => ['Size'],
    ]);
    $this->post("/interviews/{$session->id}/evaluate")->assertSessionHasNoErrors()->assertRedirect();
    Http::assertSent(function ($request) use ($requirements, $answer) {
        $schema = $request['text']['format']['schema']['properties']['criteria'];
        $input = json_decode($request['input'], true, flags: JSON_THROW_ON_ERROR);
        expect($schema['minItems'])->toBe(5)
            ->and($schema['maxItems'])->toBe(5)
            ->and($schema['items']['properties']['criterion_id']['enum'])->toBe(array_column($requirements, 'id'))
            ->and($input['transcript'][1]['id'])->toBe($answer->id)
            ->and($input['transcript'][1]['role'])->toBe('candidate');

        return true;
    });
    expect($session->evaluation()->count())->toBe(1)
        ->and($session->evaluation->result['criteria'])->toHaveCount(5)
        ->and($session->evaluation->result['criteria'][0]['evidence'][0]['message_id'])->toBe($answer->id)
        ->and($session->messages()->get()->toArray())->toBe($before);
});

test('evaluation schema binds exact saved excerpts to their candidate message IDs', function () {
    [$session] = demoInterview();
    $question = $session->messages()->create(['role' => 'assistant', 'content' => 'Question text must never be an evidence option.']);
    $answer = $session->messages()->create(['role' => 'candidate', 'content' => "Türkçe yanıt, özgün noktalama!\n".str_repeat('ğ', 450)]);
    $second = $session->messages()->create(['role' => 'candidate', 'content' => 'A different saved answer.']);
    [$other] = demoInterview();
    $other->messages()->create(['role' => 'candidate', 'content' => 'Foreign interview answer.']);
    $before = $session->messages()->get()->toArray();
    Http::fake(['api.openai.com/*' => function ($request) use ($session, $question, $answer, $second) {
        $schema = $request['text']['format']['schema']['properties']['criteria']['items']['properties']['evidence'];
        $options = $schema['items']['anyOf'];
        expect(array_column(array_column($options, 'properties'), 'message_id'))->toBe([
            ['type' => 'integer', 'enum' => [$answer->id]],
            ['type' => 'integer', 'enum' => [$second->id]],
        ]);
        foreach ($options as $option) {
            $id = $option['properties']['message_id']['enum'][0];
            expect($id)->not->toBe($question->id);
            foreach ($option['properties']['quote']['enum'] as $quote) {
                expect(mb_strlen($quote))->toBeLessThanOrEqual(400);
                expect(str_contains($id === $answer->id ? $answer->content : $second->content, $quote))->toBeTrue();
            }
        }
        expect($options[0]['properties']['quote']['enum'])->toBe([
            'Türkçe yanıt, özgün noktalama!', str_repeat('ğ', 400), str_repeat('ğ', 50),
        ]);

        return Http::response(aiResult(evaluationResult($session, $answer->id, $options[0]['properties']['quote']['enum'][0])));
    }]);
    $result = app(OpenAiInterview::class)->evaluate($session);
    expect($result['criteria'][0]['evidence'][0])->toBe(['message_id' => $answer->id, 'quote' => 'Türkçe yanıt, özgün noktalama!'])
        ->and($session->messages()->get()->toArray())->toBe($before);
});
