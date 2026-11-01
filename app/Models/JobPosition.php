<?php

namespace App\Models;

use App\Domain\Positions\Enums\EmploymentType;
use App\Domain\Positions\Enums\InterviewLanguage;
use App\Domain\Positions\Enums\PositionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $created_by
 * @property PositionStatus $status
 * @property int $version
 * @property array<int, array{id: string, text: string, category: string, display_order: int}> $requirements
 * @property array<int, array{id: string, text: string, requirement_id: ?string, required: bool, display_order: int}> $screening_topics
 */
class JobPosition extends Model
{
    protected $fillable = ['title', 'description', 'location', 'employment_type', 'interview_language', 'status', 'requirements', 'screening_topics'];

    protected function casts(): array
    {
        return ['status' => PositionStatus::class, 'employment_type' => EmploymentType::class, 'interview_language' => InterviewLanguage::class, 'requirements' => 'array', 'screening_topics' => 'array', 'version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
