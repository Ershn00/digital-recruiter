<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property CarbonImmutable|null $archived_at */
class CandidateApplication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fictional' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    public function canDeleteForDemo(): bool
    {
        if (! $this->fictional) {
            return false;
        }
        $interview = $this->interview;

        return ! $interview || ($interview->status === 'active' && $interview->question_index === 0
            && ! $interview->follow_up_used && $interview->completed_at === null
            && $interview->processing_id === null && $interview->processing_started_at === null
            && ! $interview->messages()->exists() && ! $interview->evaluation()->exists());
    }

    /** @return BelongsTo<JobPosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_position_id');
    }

    /** @return HasOne<InterviewSession, $this> */
    public function interview(): HasOne
    {
        return $this->hasOne(InterviewSession::class);
    }
}
