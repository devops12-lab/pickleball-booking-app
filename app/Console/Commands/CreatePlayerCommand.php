<?php

namespace App\Console\Commands;

use App\Models\PlayerProfile;
use App\Models\User;
use App\Services\Auth\AccountProvisioningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('player:create {--name= : Full name of the player} {--email= : Email address} {--password= : Password (min 8 characters)}')]
#[Description('Create a new player account with profile and statistics.')]
class CreatePlayerCommand extends Command
{
    public function handle(AccountProvisioningService $provisioning): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            $hasProfile = PlayerProfile::where('user_id', $existingUser->id)->exists();

            if ($hasProfile) {
                $this->warn("{$email} already has a player profile.");

                return self::SUCCESS;
            }

            if (! $this->confirm("{$email} already exists. Create a player profile for this user?")) {
                $this->info('Aborted.');

                return self::FAILURE;
            }
        }

        $provisioning->provisionPlayer([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Player account created for {$email}.");

        return self::SUCCESS;
    }
}
