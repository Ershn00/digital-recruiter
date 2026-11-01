<?php

namespace App\Domain\Positions\Actions;

use App\Domain\Positions\Enums\PositionStatus;
use App\Models\JobPosition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavePosition
{
    /** @param array<string, mixed> $data */
    public function handle(User $creator, array $data, ?JobPosition $position = null): JobPosition
    {
        return DB::transaction(function () use ($creator, $data, $position): JobPosition {
            if ($position) {
                $position = JobPosition::query()->lockForUpdate()->findOrFail($position->id);
                abort_unless($position->created_by === $creator->id, 403);
                if ($position->status === PositionStatus::Archived) {
                    throw ValidationException::withMessages(['status' => 'Archived positions cannot be edited.']);
                }
                if ($position->version !== (int) $data['version']) {
                    throw ValidationException::withMessages(['version' => 'This position changed. Reload the page before saving.']);
                }
                $position->version++;
            } else {
                $position = new JobPosition;
                $position->created_by = $creator->id;
            }
            unset($data['version']);
            foreach (['requirements', 'screening_topics'] as $key) {
                usort($data[$key], fn (array $a, array $b): int => $a['display_order'] <=> $b['display_order']);
                foreach ($data[$key] as $index => &$item) {
                    $item['display_order'] = $index;
                }
                unset($item);
            }
            foreach ($data['screening_topics'] as &$topic) {
                $topic['required'] = (bool) $topic['required'];
            }
            unset($topic);
            $position->fill($data)->save();

            return $position;
        });
    }
}
