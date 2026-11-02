<?php

namespace Database\Seeders;

use App\Domain\Positions\Actions\ArchivePosition;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Process environment only: works with config:cache and keeps secrets out of config caches.
        if (getenv('DEMO_SEEDING_ENABLED') !== 'true') {
            throw new RuntimeException('Demo seeding requires DEMO_SEEDING_ENABLED=true.');
        }

        $passwords = [];
        for ($number = 1; $number <= 3; $number++) {
            $password = getenv("DEMO_USER_{$number}_PASSWORD");
            if (! is_string($password) || strlen($password) < 12 || strlen($password) > 72
                || ! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password)
                || ! preg_match('/[0-9]/', $password) || ! preg_match('/[^a-zA-Z0-9]/', $password)) {
                throw new RuntimeException("DEMO_USER_{$number}_PASSWORD must contain 12–72 bytes with lowercase, uppercase, a number, and a symbol.");
            }
            $passwords[$number] = $password;
        }

        DB::transaction(function () use ($passwords): void {
            $users = [];
            foreach ($passwords as $number => $password) {
                $id = -910000 - $number;
                $email = "test{$number}@example.com";
                $matches = User::query()->where('id', $id)->orWhereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->get();
                if ($matches->isNotEmpty()) {
                    $user = $matches->first();
                    if ($matches->count() !== 1 || $user->id !== $id || $user->email !== $email) {
                        throw new RuntimeException("Demo user collision for {$email}; no existing account will be adopted.");
                    }
                } else {
                    $user = new User;
                    $user->forceFill(['id' => $id, 'name' => "Test User {$number}", 'email' => $email,
                        'password' => $password, 'email_verified_at' => now()])->save();
                }
                $users[$number] = $user;
            }

            foreach ($this->positions() as $index => $data) {
                $id = -910001 - $index;
                $owner = $users[$index < 3 ? 1 : 2];
                $position = JobPosition::query()->lockForUpdate()->find($id);
                if ($position) {
                    if ($position->created_by !== $owner->id) {
                        throw new RuntimeException("Demo position ID collision: {$id}.");
                    }

                    // Preserve all edits, including status, JSON, and version, on subsequent runs.
                    continue;
                }

                $requirements = [];
                $topics = [];
                foreach ($data['requirements'] as $order => $text) {
                    $requirements[] = ['id' => $this->uuid($index, 1, $order), 'text' => $text,
                        'category' => 'essential', 'display_order' => $order];
                }
                foreach ($data['topics'] as $order => $text) {
                    $topics[] = ['id' => $this->uuid($index, 2, $order), 'text' => $text,
                        'requirement_id' => $requirements[$order]['id'], 'required' => true, 'display_order' => $order];
                }
                $position = new JobPosition;
                $position->forceFill(['id' => $id, 'created_by' => $owner->id, 'title' => $data['title'],
                    'description' => $data['description'], 'location' => $data['location'],
                    'employment_type' => 'full_time', 'interview_language' => 'en', 'status' => 'active',
                    'requirements' => $requirements, 'screening_topics' => $topics])->save();
                if ($index === 4) {
                    app(ArchivePosition::class)->handle($owner, $position);
                }
            }
        });
    }

    private function uuid(int $position, int $kind, int $order): string
    {
        return sprintf('d3910000-0000-4000-8000-%04d%04d%04d', $position + 1, $kind, $order + 1);
    }

    /** @return list<array{title: string, location: string, description: string, requirements: list<string>, topics: list<string>}> */
    private function positions(): array
    {
        return [
            [
                'title' => 'Warehouse Associate — Night Shift', 'location' => 'Fremont, CA',
                'description' => 'Join a regional distribution team preparing customer orders overnight. Receive and put away stock, pick and pack orders, and complete accurate handovers to the morning team. Follow established equipment and lifting procedures while working with the shift lead to meet dispatch deadlines.',
                'requirements' => ['Experience receiving, picking, or packing in a warehouse.', 'Availability for a regular overnight schedule, including scheduled weekends.', 'Ability to follow workplace safety and safe material-handling procedures.', 'Experience scanning inventory and checking quantities against order records.'],
                'topics' => ['Describe your warehouse experience and typical responsibilities.', 'What overnight shifts can you consistently work?', 'How would you respond to an unsafe condition during a busy shift?', 'How do you investigate a mismatch between physical stock and inventory records?'],
            ],
            [
                'title' => 'Customer Support Specialist', 'location' => 'Austin, TX',
                'description' => 'Help customers use a subscription software product through email and live chat. Investigate reported issues, document clear next steps, and coordinate escalations with product and engineering teams. Balance response times with thoughtful, accurate service and maintain useful support records.',
                'requirements' => ['Experience communicating clearly and empathetically with customers.', 'Ability to troubleshoot problems and identify practical next steps.', 'Familiarity with CRM or help-desk ticketing tools.', 'Strong written communication for customer replies and internal handovers.'],
                'topics' => ['Tell us about a customer interaction you handled successfully.', 'How would you resolve a frustrated customer’s recurring issue?', 'How have you used support tools to document and escalate a ticket?', 'How do you prioritize urgent tickets while keeping written updates clear?'],
            ],
            [
                'title' => 'Backend PHP Developer', 'location' => 'Remote, US',
                'description' => 'Build and maintain backend services for a growing recruitment SaaS product. Work with product and frontend colleagues to deliver reliable APIs, evolve relational data models, and improve automated coverage. Participate in code review and document decisions for a distributed US team.',
                'requirements' => ['Practical experience developing production applications with PHP and Laravel.', 'Experience designing and consuming REST APIs.', 'Ability to design relational schemas and write efficient SQL queries.', 'Experience writing automated feature and unit tests.', 'Familiarity with Git workflows and collaborative code review.'],
                'topics' => ['Describe a Laravel architecture decision and its tradeoffs.', 'How would you design and query a relational model for a REST API?', 'How do you handle validation, authorization, and errors in an API?', 'What tests would you write before releasing a backend change?', 'Describe how you collaborate through Git branches and code review.'],
            ],
            [
                'title' => 'Operations Coordinator', 'location' => 'Chicago, IL',
                'description' => 'Coordinate day-to-day schedules and operational handoffs for a growing service team. Keep delivery plans and process documentation current, track outstanding actions, and communicate changes to internal stakeholders. Identify recurring bottlenecks and help the team improve dependable execution.',
                'requirements' => ['Experience scheduling people, tasks, or service deliveries.', 'Ability to coordinate multi-step processes and track dependencies.', 'Strong documentation skills and attention to operational detail.', 'Clear communication with cross-functional stakeholders.'],
                'topics' => ['Walk us through an operational workflow you have coordinated.', 'How do you prioritize when several scheduled tasks become urgent?', 'How do you document and communicate a change to stakeholders?', 'Describe a process improvement and how you measured its impact.'],
            ],
            [
                'title' => 'Sales Development Representative', 'location' => 'Denver, CO',
                'description' => 'Support a B2B software sales team by researching prospects, initiating relevant outreach, and qualifying inbound interest. Maintain accurate CRM records and arrange discovery meetings with account executives. This completed hiring campaign remains available as an archived example for reviewing past position details.',
                'requirements' => ['Experience researching prospects and conducting outbound outreach.', 'Ability to maintain accurate activity and contact records in a CRM.', 'Clear verbal and written communication with prospective customers.', 'Understanding of lead qualification and sales handover practices.'],
                'topics' => ['Describe an outreach campaign and how you selected prospects.', 'How do you keep CRM records useful throughout the outreach process?', 'How would you respond to a prospect who says they already have a solution?', 'What questions help you qualify a lead before an account executive handover?'],
            ],
        ];
    }
}
