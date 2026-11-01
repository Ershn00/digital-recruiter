<?php

namespace App\Domain\Positions\Actions;

use App\Domain\Positions\Enums\PositionStatus;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchivePosition
{
    public function handle(User $user, JobPosition $position): void
    {
        DB::transaction(function () use ($user, $position): void {
            $position = JobPosition::query()->lockForUpdate()->findOrFail($position->id);
            abort_unless($position->created_by === $user->id, 403);
            if ($position->status !== PositionStatus::Archived) {
                $position->status = PositionStatus::Archived;
                $position->version++;
                $position->save();
            }
        });
    }
}
