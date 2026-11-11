<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;

class ClubPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        return null;
    }

    public function view(User $user, Club $club): bool
    {
        if ($club->privacy === 'public') {
            return true;
        }

        if ($club->creator_id === $user->id) {
            return true;
        }

        return ClubMember::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function update(User $user, Club $club): bool
    {
        if ($club->creator_id === $user->id) {
            return true;
        }

        return ClubMember::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['manager', 'organizer'])
            ->where('status', 'active')
            ->exists();
    }

    public function delete(User $user, Club $club): bool
    {
        return $club->creator_id === $user->id;
    }

    public function manageMembers(User $user, Club $club): bool
    {
        if ($club->creator_id === $user->id) {
            return true;
        }

        return ClubMember::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->whereIn('role', ['manager', 'organizer'])
            ->where('status', 'active')
            ->exists();
    }
}
