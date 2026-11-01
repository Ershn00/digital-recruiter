<?php

namespace App\Policies;

use App\Models\JobPosition;
use App\Models\User;

class JobPositionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobPosition $position): bool
    {
        return $position->created_by === $user->id;
    }

    public function update(User $user, JobPosition $position): bool
    {
        return $this->view($user, $position);
    }

    public function archive(User $user, JobPosition $position): bool
    {
        return $this->view($user, $position);
    }
}
