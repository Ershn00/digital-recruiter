<?php

use App\Domain\Positions\Enums\PositionStatus;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

function positionPayload(): array
{
    $id = (string) Str::uuid();

    return [
        'title' => 'Backend Engineer', 'description' => 'Build recruitment software.',
        'location' => 'Istanbul / remote', 'employment_type' => 'full_time',
        'interview_language' => 'en', 'status' => 'draft',
        'requirements' => [['id' => $id, 'text' => 'PostgreSQL experience', 'category' => 'essential', 'display_order' => 0]],
        'screening_topics' => [['id' => (string) Str::uuid(), 'text' => 'Describe a database change.', 'requirement_id' => $id, 'required' => true, 'display_order' => 0]],
    ];
}

function ownedPosition(User $owner): JobPosition
{
    $position = new JobPosition(positionPayload());
    $position->created_by = $owner->id;
    $position->save();

    return $position;
}

test('all position routes require authentication', function () {
    $position = ownedPosition(User::factory()->create());
    foreach (['/positions', '/positions/create', "/positions/$position->id", "/positions/$position->id/edit"] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    $this->post('/positions', positionPayload())->assertRedirect('/login');
    $this->patch("/positions/$position->id", positionPayload())->assertRedirect('/login');
    $this->post("/positions/$position->id/archive")->assertRedirect('/login');
});

test('positions are private to their creator', function () {
    $owner = User::factory()->create();
    $position = ownedPosition($owner);
    $this->actingAs(User::factory()->create());
    $this->get('/positions')->assertInertia(fn (Assert $page) => $page->component('positions/index')->has('positions.data', 0));
    $this->get("/positions/$position->id")->assertForbidden();
    $this->get("/positions/$position->id/edit")->assertForbidden();
    $this->patch("/positions/$position->id", [...positionPayload(), 'version' => 1])->assertForbidden();
    $this->post("/positions/$position->id/archive")->assertForbidden();
    expect($position->fresh()->status)->toBe(PositionStatus::Draft);
});

test('owner can visit all pages and create a position without assigning another owner', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->get('/positions/create')->assertInertia(fn (Assert $page) => $page->component('positions/create'));
    $data = positionPayload();
    $this->post('/positions', [...$data, 'created_by' => 999, 'schema_version' => 999])->assertRedirect();
    $position = JobPosition::firstOrFail();
    expect($position->created_by)->toBe($owner->id)->and($position->requirements)->toBe($data['requirements'])->and($position->screening_topics)->toBe($data['screening_topics'])->and($position->version)->toBe(1)->and($position->schema_version)->toBe(1);
    $this->get("/positions/$position->id")->assertInertia(fn (Assert $page) => $page->component('positions/show')->where('position.title', $data['title']));
    $this->get("/positions/$position->id/edit")->assertInertia(fn (Assert $page) => $page->component('positions/edit'));
    $this->get('/positions')->assertInertia(fn (Assert $page) => $page->has('positions.data', 1));
});

test('invalid basic and nested fields are rejected', function (string $key, mixed $value) {
    $this->actingAs(User::factory()->create());
    $data = positionPayload();
    data_set($data, $key, $value);
    $this->post('/positions', $data)->assertSessionHasErrors($key);
    expect(JobPosition::count())->toBe(0);
})->with([
    ['title', ''], ['description', ''], ['location', ''], ['employment_type', 'unknown'],
    ['interview_language', 'unknown'], ['status', 'archived'], ['requirements', null],
    ['requirements.0.id', 'invalid'], ['requirements.0.text', ''], ['requirements.0.category', 'unknown'],
    ['requirements.0.display_order', -1], ['screening_topics.0.id', 'invalid'],
    ['screening_topics.0.text', ''], ['screening_topics.0.required', 'yes'],
    ['screening_topics.0.requirement_id', 'invalid'], ['screening_topics.0.display_order', -1],
]);

test('unknown nested keys duplicate identifiers and orders are rejected', function () {
    $this->actingAs(User::factory()->create());
    $data = positionPayload();
    $data['requirements'][] = $data['requirements'][0];
    $data['screening_topics'][] = $data['screening_topics'][0];
    $this->post('/positions', $data)->assertSessionHasErrors(['requirements.0.id', 'requirements.0.display_order', 'screening_topics.0.id']);
    $data = positionPayload();
    $data['requirements'][0]['score'] = 100;
    $this->post('/positions', $data)->assertSessionHasErrors('requirements.0');
});

test('screening references must exist in the submitted requirements even on update', function () {
    $owner = User::factory()->create();
    $position = ownedPosition($owner);
    $this->actingAs($owner);
    $data = positionPayload();
    $data['screening_topics'][0]['requirement_id'] = (string) Str::uuid();
    $this->post('/positions', $data)->assertSessionHasErrors('screening_topics.0.requirement_id');
    $data['screening_topics'][0]['requirement_id'] = $position->requirements[0]['id'];
    $this->patch("/positions/$position->id", [...$data, 'version' => 1])->assertSessionHasErrors('screening_topics.0.requirement_id');
    expect($position->fresh()->version)->toBe(1);
});

test('owner can edit add remove and reorder while preserving identifiers and references', function () {
    $owner = User::factory()->create();
    $data = positionPayload();
    $data['requirements'][] = ['id' => (string) Str::uuid(), 'text' => 'PHP', 'category' => 'desirable', 'display_order' => 1];
    $data['screening_topics'][] = ['id' => (string) Str::uuid(), 'text' => 'PHP example', 'requirement_id' => null, 'required' => false, 'display_order' => 1];
    $this->actingAs($owner)->post('/positions', $data)->assertRedirect();
    $position = JobPosition::firstOrFail();
    $data['title'] = 'Senior Backend Engineer';
    $data['status'] = 'active';
    $data['requirements'][0]['text'] = 'PostgreSQL migrations';
    $data['requirements'][0]['display_order'] = 1;
    $data['requirements'][1]['display_order'] = 0;
    $data['screening_topics'][0]['display_order'] = 1;
    $data['screening_topics'][1]['display_order'] = 0;
    $this->patch("/positions/$position->id", [...$data, 'version' => 1])->assertRedirect();
    $position->refresh();
    expect($position->title)->toBe($data['title'])->and($position->status)->toBe(PositionStatus::Active)->and($position->version)->toBe(2)
        ->and(array_column($position->requirements, 'id'))->toBe(array_reverse(array_column($data['requirements'], 'id')))
        ->and(array_column($position->screening_topics, 'id'))->toBe(array_reverse(array_column($data['screening_topics'], 'id')))
        ->and($position->screening_topics[1]['requirement_id'])->toBe($data['requirements'][0]['id']);
    $data['requirements'] = [$position->requirements[0]];
    $data['screening_topics'] = [$position->screening_topics[0]];
    $this->patch("/positions/$position->id", [...$data, 'version' => 2])->assertRedirect();
    expect($position->fresh()->requirements)->toHaveCount(1)->and($position->fresh()->screening_topics)->toHaveCount(1);
});

test('empty lists and unlinked optional topics are supported', function () {
    $this->actingAs(User::factory()->create());
    $data = positionPayload();
    $data['requirements'] = [];
    $data['screening_topics'][0]['requirement_id'] = null;
    $data['screening_topics'][0]['required'] = false;
    $this->post('/positions', $data)->assertRedirect();
    $data['screening_topics'] = [];
    $this->post('/positions', $data)->assertRedirect();
    expect(JobPosition::count())->toBe(2);
});

test('stale updates do not overwrite newer versions', function () {
    $owner = User::factory()->create();
    $position = ownedPosition($owner);
    $this->actingAs($owner)->patch("/positions/$position->id", [...positionPayload(), 'version' => 99])->assertSessionHasErrors('version');
    expect($position->fresh()->version)->toBe(1);
});

test('archive is repeatable and archived positions are immutable and viewable', function () {
    $owner = User::factory()->create();
    $position = ownedPosition($owner);
    $this->actingAs($owner)->post("/positions/$position->id/archive")->assertRedirect("/positions/$position->id");
    expect($position->fresh()->status)->toBe(PositionStatus::Archived)->and($position->fresh()->version)->toBe(2);
    $before = $position->fresh()->getAttributes();
    $this->post("/positions/$position->id/archive")->assertRedirect();
    $this->patch("/positions/$position->id", [...positionPayload(), 'version' => 2])->assertSessionHasErrors('status');
    expect($position->fresh()->getAttributes())->toBe($before);
    $this->get("/positions/$position->id/edit")->assertRedirect("/positions/$position->id");
    $this->get("/positions/$position->id")->assertOk();
    $this->delete("/positions/$position->id")->assertStatus(405);
});

test('unverified users must verify before managing positions', function () {
    $this->actingAs(User::factory()->unverified()->create())->get('/positions')->assertRedirect(route('verification.notice'));
    $this->post('/positions', positionPayload())->assertRedirect(route('verification.notice'));
    expect(JobPosition::count())->toBe(0);
});

test('missing lists and non-list documents are rejected', function () {
    $this->actingAs(User::factory()->create());
    $data = positionPayload();
    unset($data['requirements']);
    $this->post('/positions', $data)->assertSessionHasErrors('requirements');
    $data = positionPayload();
    $data['requirements'] = ['arbitrary-key' => $data['requirements'][0]];
    $this->post('/positions', $data)->assertSessionHasErrors('requirements');
});

test('wire boolean values are persisted as JSON booleans', function () {
    $this->actingAs(User::factory()->create());
    $data = positionPayload();
    $data['screening_topics'][0]['required'] = '0';
    $this->post('/positions', $data)->assertRedirect();
    expect(JobPosition::firstOrFail()->screening_topics[0]['required'])->toBeFalse();
});
