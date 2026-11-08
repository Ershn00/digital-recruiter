<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property array{title: string, description: string, version: int, interview_language: string, requirements: list<array{id: string, text: string, category: string, display_order: int}>, screening_topics: list<array{id: string, text: string, requirement_id: ?string, required: bool, display_order: int}>} $criteria_snapshot
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $completed_at
 */
class InterviewSession extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token_hash', 'processing_id'];

    public function linkVersion(): string
    {
        // An optimistic concurrency fingerprint, not a candidate access credential.
        return hash('sha256', 'interview-link:'.$this->access_token_hash);
    }

    protected function casts(): array
    {
        return ['criteria_snapshot' => 'array', 'expires_at' => 'immutable_datetime', 'processing_started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'follow_up_used' => 'boolean', 'question_index' => 'integer'];
    }

    /** @return BelongsTo<CandidateApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class, 'candidate_application_id');
    }

    /** @return HasMany<InterviewMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(InterviewMessage::class)->orderBy('id');
    }

    /** @return HasOne<CandidateEvaluation, $this> */
    public function evaluation(): HasOne
    {
        return $this->hasOne(CandidateEvaluation::class);
    }
}
