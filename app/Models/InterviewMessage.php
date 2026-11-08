<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterviewMessage extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<InterviewSession, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class, 'interview_session_id');
    }
}
