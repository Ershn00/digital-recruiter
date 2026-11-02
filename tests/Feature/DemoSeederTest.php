<?php

use App\Domain\Positions\Enums\PositionStatus;
use App\Http\Requests\Positions\SavePositionRequest;
use App\Models\JobPosition;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->demoEnvironment = [];
    foreach (['DEMO_SEEDING_ENABLED', 'DEMO_USER_1_PASSWORD', 'DEMO_USER_2_PASSWORD', 'DEMO_USER_3_PASSWORD'] as $key) {
        $this->demoEnvironment[$key] = getenv($key);
        putenv($key.'='.($key === 'DEMO_SEEDING_ENABLED' ? 'true' : bin2hex(random_bytes(16)).'aA1!'));
    }
});

afterEach(function () {
    foreach ($this->demoEnvironment as $key => $value) {
        putenv($value === false ? $key : $key.'='.$value);
    }
});

test('demo seeder creates verified accounts and five valid owned positions', function () {
    $this->seed(DemoSeeder::class);
    expect(User::count())->toBe(3)->and(JobPosition::count())->toBe(5)
        ->and(JobPosition::where('status', 'active')->count())->toBe(4)
        ->and(JobPosition::where('status', 'archived')->count())->toBe(1);

    foreach ([1 => 3, 2 => 2, 3 => 0] as $number => $count) {
        $user = User::where('email', "test{$number}@example.com")->firstOrFail();
        expect($user->name)->toBe("Test User {$number}")
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check(getenv("DEMO_USER_{$number}_PASSWORD"), $user->password))->toBeTrue()
            ->and(JobPosition::where('created_by', $user->id)->count())->toBe($count);
    }

    $titles = ['Warehouse Associate — Night Shift', 'Customer Support Specialist', 'Backend PHP Developer', 'Operations Coordinator', 'Sales Development Representative'];
    foreach ($titles as $index => $title) {
        $position = JobPosition::where('title', $title)->firstOrFail();
        expect($position->creator->email)->toBe($index < 3 ? 'test1@example.com' : 'test2@example.com');
        $request = SavePositionRequest::create('/positions', 'POST');
        $payload = $position->attributesToArray();
        // Archived records are created active, then archived through the domain action.
        $payload['status'] = 'active';
        unset($payload['version']);
        $request->replace($payload);
        $validator = Validator::make($payload, $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }
        expect($validator->passes())->toBeTrue()
            ->and(count($position->requirements))->toBeBetween(3, 5)
            ->and(count($position->screening_topics))->toBeBetween(3, 5);
        foreach (['requirements', 'screening_topics'] as $field) {
            expect(array_column($position->$field, 'display_order'))->toBe(range(0, count($position->$field) - 1));
        }
    }
    $archived = JobPosition::where('status', 'archived')->firstOrFail();
    expect($archived->title)->toBe('Sales Development Representative')->and($archived->version)->toBe(2);
});

test('reruns preserve passwords edited demo records and user generated records', function () {
    $unrelated = User::factory()->create();
    $this->seed(DemoSeeder::class);
    $user = User::where('email', 'test1@example.com')->firstOrFail();
    $user->password = bin2hex(random_bytes(20));
    $user->name = 'Renamed demo user';
    $user->save();
    $position = JobPosition::where('created_by', $user->id)->firstOrFail();
    $position->title = 'User edited title';
    $position->requirements = [];
    $position->status = PositionStatus::Archived;
    $position->save();
    $custom = $position->replicate();
    $custom->title = 'User created position';
    $custom->save();
    $beforeUsers = User::orderBy('id')->get()->map->getAttributes()->all();
    $beforePositions = JobPosition::orderBy('id')->get()->map->getAttributes()->all();
    putenv('DEMO_USER_1_PASSWORD='.bin2hex(random_bytes(16)).'aA1!');
    $this->seed(DemoSeeder::class);
    expect(User::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeUsers)
        ->and(JobPosition::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforePositions)
        ->and($unrelated->fresh())->not->toBeNull();
});

test('missing or weak credentials and missing opt in fail before writes', function (string $key, ?string $value) {
    putenv($value === null ? $key : $key.'='.$value);
    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, $key);
    expect(User::count())->toBe(0)->and(JobPosition::count())->toBe(0);
})->with([
    ['DEMO_SEEDING_ENABLED', null], ['DEMO_SEEDING_ENABLED', 'false'],
    ['DEMO_USER_1_PASSWORD', null], ['DEMO_USER_2_PASSWORD', null], ['DEMO_USER_3_PASSWORD', null],
    ['DEMO_USER_1_PASSWORD', 'weak'], ['DEMO_USER_2_PASSWORD', 'abcdefghijklmnop'],
    ['DEMO_USER_3_PASSWORD', str_repeat('a', 73).'A1!'],
]);

test('email collisions are never adopted and all inserts roll back', function (string $email) {
    $existing = User::factory()->create(['email' => $email, 'name' => 'Test User 2']);
    $before = $existing->fresh()->getAttributes();
    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, 'collision');
    expect(User::count())->toBe(1)->and(JobPosition::count())->toBe(0)
        ->and($existing->fresh()->getAttributes())->toBe($before);
})->with(['test2@example.com', 'TEST2@example.com']);

test('reserved user id collisions fail safely', function () {
    $user = User::factory()->create(['id' => -910002]);
    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, 'collision');
    expect(User::count())->toBe(1)->and($user->fresh()->email)->toBe($user->email);
});

test('reserved position id collisions roll back demo accounts', function () {
    $owner = User::factory()->create();
    $position = new JobPosition(['title' => 'Unrelated', 'description' => 'Keep this position.', 'location' => 'Remote',
        'employment_type' => 'full_time', 'interview_language' => 'en', 'status' => 'active', 'requirements' => [], 'screening_topics' => []]);
    $position->id = -910005;
    $position->created_by = $owner->id;
    $position->save();
    $before = $position->fresh()->getAttributes();
    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, 'collision');
    expect(User::count())->toBe(1)->and(JobPosition::count())->toBe(1)
        ->and($position->fresh()->getAttributes())->toBe($before);
});

test('explicit opt in permits production environment with an isolated test connection', function () {
    $environment = app()->environment();
    app()->instance('env', 'production');
    try {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();
        expect(User::count())->toBe(3)->and(JobPosition::count())->toBe(5);
    } finally {
        app()->instance('env', $environment);
    }
});

test('third demo user sees the positions empty state', function () {
    $this->withoutVite();
    $this->seed(DemoSeeder::class);
    $user = User::where('email', 'test3@example.com')->firstOrFail();
    $this->actingAs($user)->get('/positions')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('positions/index')->has('positions.data', 0));
});

test('PostgreSQL stores signed keys and ordered JSONB with a repeatable archive state', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the guarded disposable PostgreSQL database.');
    }
    $this->seed(DemoSeeder::class);
    $columns = DB::select("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN ('users', 'job_positions')");
    $types = [];
    foreach ($columns as $column) {
        $types[$column->table_name.'.'.$column->column_name] = $column->data_type;
    }
    expect($types['users.id'])->toBe('bigint')->and($types['job_positions.id'])->toBe('bigint')
        ->and($types['job_positions.created_by'])->toBe('bigint')
        ->and($types['job_positions.requirements'])->toBe('jsonb')
        ->and($types['job_positions.screening_topics'])->toBe('jsonb');

    $invalid = DB::selectOne(<<<'SQL'
        SELECT count(*) AS total FROM job_positions p
        CROSS JOIN LATERAL jsonb_array_elements(p.screening_topics) WITH ORDINALITY AS t(topic, ordinal)
        WHERE jsonb_typeof(topic->'required') <> 'boolean'
           OR (topic->>'display_order')::int <> ordinal - 1
           OR NOT EXISTS (
               SELECT 1 FROM jsonb_array_elements(p.requirements) r
               WHERE r->>'id' = topic->>'requirement_id'
           )
        SQL);
    expect((int) $invalid->total)->toBe(0);
    $before = JobPosition::orderBy('id')->get()->map->getAttributes()->all();
    $passwords = User::orderBy('id')->pluck('password')->all();
    $this->seed(DemoSeeder::class);
    expect(JobPosition::orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(User::orderBy('id')->pluck('password')->all())->toBe($passwords)
        ->and(JobPosition::where('status', 'archived')->value('version'))->toBe(2);
});

test('PostgreSQL enforces the position owner foreign key', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the guarded disposable PostgreSQL database.');
    }
    $this->seed(DemoSeeder::class);
    try {
        // A savepoint contains PostgreSQL's failed transaction state within this attempt.
        DB::transaction(fn () => DB::table('job_positions')->where('id', -910001)->update(['created_by' => -919999]));
        $this->fail('PostgreSQL accepted a nonexistent position owner.');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23503');
    }
    expect(JobPosition::findOrFail(-910001)->creator->email)->toBe('test1@example.com');
});
