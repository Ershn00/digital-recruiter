<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $result */
class CandidateEvaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    /** @return BelongsTo<InterviewSession, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class, 'interview_session_id');
    }
}
