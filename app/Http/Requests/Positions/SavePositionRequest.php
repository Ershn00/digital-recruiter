<?php

namespace App\Http\Requests\Positions;

use App\Domain\Positions\Enums\EmploymentType;
use App\Domain\Positions\Enums\InterviewLanguage;
use App\Domain\Positions\Enums\PositionStatus;
use App\Domain\Positions\Enums\RequirementCategory;
use App\Models\JobPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $position = $this->route('position');

        return $position instanceof JobPosition ? ($this->user()?->can('update', $position) ?? false) : ($this->user()?->can('create', JobPosition::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:20000'],
            'location' => ['required', 'string', 'max:200'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'interview_language' => ['required', Rule::enum(InterviewLanguage::class)],
            'status' => ['required', Rule::in([PositionStatus::Draft->value, PositionStatus::Active->value])],
            'version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'requirements' => ['present', 'array', 'list', 'max:50'],
            'requirements.*' => ['required', 'array:id,text,category,display_order'],
            'requirements.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'requirements.*.text' => ['required', 'string', 'max:2000'],
            'requirements.*.category' => ['required', Rule::enum(RequirementCategory::class)],
            'requirements.*.display_order' => ['required', 'integer', 'min:0', 'max:49', 'distinct'],
            'screening_topics' => ['present', 'array', 'list', 'max:50'],
            'screening_topics.*' => ['required', 'array:id,text,requirement_id,required,display_order'],
            'screening_topics.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'screening_topics.*.text' => ['required', 'string', 'max:2000'],
            'screening_topics.*.requirement_id' => ['present', 'nullable', 'uuid'],
            'screening_topics.*.required' => ['required', 'boolean'],
            'screening_topics.*.display_order' => ['required', 'integer', 'min:0', 'max:49', 'distinct'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $ids = array_column($this->input('requirements', []), 'id');
            foreach ($this->input('screening_topics', []) as $index => $topic) {
                if ($topic['requirement_id'] !== null && ! in_array($topic['requirement_id'], $ids, true)) {
                    $validator->errors()->add("screening_topics.$index.requirement_id", 'Select a requirement included in this position, or remove the reference.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return ['status.in' => 'Choose draft or active. Use the archive action to archive a position.', 'requirements.*.id.distinct' => 'Each requirement must have a unique identifier.', 'screening_topics.*.id.distinct' => 'Each screening topic must have a unique identifier.'];
    }
}
