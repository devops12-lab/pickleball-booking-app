<?php

namespace App\Policies;

use App\Models\Tournament;
use App\Models\User;

class TournamentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        return null;
    }

    public function view(User $user, Tournament $tournament): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('club_manager') || $user->hasRole('owner') || $user->isSystemAdmin();
    }

    public function update(User $user, Tournament $tournament): bool
    {
        return $tournament->organizer_id === $user->id;
    }

    public function delete(User $user, Tournament $tournament): bool
    {
        return $tournament->organizer_id === $user->id;
    }

    public function submitScore(User $user, Tournament $tournament): bool
    {
        return $tournament->organizer_id === $user->id;
    }
}
