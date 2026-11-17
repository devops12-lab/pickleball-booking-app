<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\Auth\AccountProvisioningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('owner:create {--name= : Full name of the facility owner} {--email= : Email address} {--password= : Password (min 8 characters)} {--business-name= : Name of the facility/business} {--business-email= : Business contact email} {--business-phone= : Business phone number} {--address= : Business address}')]
#[Description('Create a new facility owner account with a tenant (business facility).')]
class CreateOwnerCommand extends Command
{
    public function handle(AccountProvisioningService $provisioning): int
    {
        // Detect non-interactive mode: any required option passed, or --no-interaction flag.
        $scripted = $this->option('name') !== null || $this->option('no-interaction');

        $name = $this->option('name') ?: ($scripted ? '' : $this->ask('Full name'));
        $email = $this->option('email') ?: ($scripted ? '' : $this->ask('Email address'));
        $password = $this->option('password') ?: ($scripted ? '' : $this->secret('Password (min 8 characters)'));
        $businessName = $this->option('business-name') ?: ($scripted ? '' : $this->ask('Business / facility name'));

        // Optional fields default to empty in non-interactive mode.
        $businessEmail = $this->option('business-email') ?? ($scripted ? '' : $this->ask('Business contact email (leave blank to use owner email)', ''));
        $businessPhone = $this->option('business-phone') ?? ($scripted ? '' : $this->ask('Business phone (optional)', ''));
        $address = $this->option('address') ?? ($scripted ? '' : $this->ask('Business address (optional)', ''));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'business_name' => $businessName],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                'business_name' => ['required', 'string', 'max:255'],
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
            $ownerRole = Role::where('name', 'owner')->first();
            $isOwner = $ownerRole && TenantUser::where('user_id', $existingUser->id)
                ->where('role_id', $ownerRole->id)
                ->exists();

            if ($isOwner) {
                $this->warn("{$email} is already a facility owner.");

                return self::SUCCESS;
            }

            if (! $this->confirm("{$email} already exists. Create a new facility and assign this user as owner?")) {
                $this->info('Aborted.');

                return self::FAILURE;
            }
        }

        $result = $provisioning->provisionOwner(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ],
            [
                'name' => $businessName,
                'business_email' => $businessEmail ?: $email,
                'business_phone' => $businessPhone ?: null,
                'address' => $address ?: null,
            ]
        );

        $this->info("Owner account created for {$email}.");
        $this->info("Facility \"{$result['tenant']->name}\" (slug: {$result['tenant']->slug}) has been provisioned.");

        return self::SUCCESS;
    }
}
