<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('organizer:create {--email= : Email of existing user to assign} {--club= : Club slug or ID}')]
#[Description('Assign an existing user as a club organizer.')]
class CreateOrganizerCommand extends Command
{
    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email of the user to assign as organizer');
        $clubInput = $this->option('club') ?: $this->ask('Club slug or ID');

        $validator = Validator::make(
            ['email' => $email, 'club' => $clubInput],
            [
                'email' => ['required', 'email', 'max:255'],
                'club' => ['required', 'string'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User \"{$email}\" not found. Organizers must be existing users.");

            return self::FAILURE;
        }

        $club = is_numeric($clubInput)
            ? Club::find($clubInput)
            : Club::where('slug', $clubInput)->first();

        if (! $club) {
            $this->error("Club \"{$clubInput}\" not found.");

            return self::FAILURE;
        }

        $existingMembership = ClubMember::where('club_id', $club->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingMembership && in_array($existingMembership->role, ['organizer', 'manager'])) {
            $article = in_array($existingMembership->role[0], ['a', 'e', 'i', 'o', 'u']) ? 'an' : 'a';
            $this->warn("{$email} is already {$article} {$existingMembership->role} of \"{$club->name}\".");

            return self::SUCCESS;
        }

        if ($existingMembership) {
            if (! $this->confirm("{$email} is a {$existingMembership->role} of \"{$club->name}\". Promote to organizer?")) {
                $this->info('Aborted.');

                return self::FAILURE;
            }

            $existingMembership->update(['role' => 'organizer']);
            $this->info("{$email} has been promoted to organizer of \"{$club->name}\".");

            return self::SUCCESS;
        }

        ClubMember::create([
            'club_id' => $club->id,
            'user_id' => $user->id,
            'role' => 'organizer',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->info("{$email} has been assigned as organizer of \"{$club->name}\".");

        return self::SUCCESS;
    }
}
