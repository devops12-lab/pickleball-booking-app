<?php

namespace App\Console\Commands;

use App\Models\PlayerStatistic;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('staff:create {--name= : Full name of the staff member} {--email= : Email address} {--password= : Password (min 8 characters)} {--tenant= : Tenant slug or ID}')]
#[Description('Create a new facility staff account and assign it to a tenant.')]
class CreateStaffCommand extends Command
{
    public function handle(): int
    {
        $staffRole = Role::where('name', 'staff')->first();

        if (! $staffRole) {
            $this->error('The staff role does not exist. Run php artisan db:seed --class=RolesAndPermissionsSeeder first.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');
        $tenantInput = $this->option('tenant') ?: $this->ask('Tenant slug or ID');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'tenant' => $tenantInput],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
                'tenant' => ['required', 'string'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $tenant = is_numeric($tenantInput)
            ? Tenant::find($tenantInput)
            : Tenant::where('slug', $tenantInput)->first();

        if (! $tenant) {
            $this->error("Tenant \"{$tenantInput}\" not found.");

            return self::FAILURE;
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            $alreadyStaff = TenantUser::where([
                'tenant_id' => $tenant->id,
                'user_id' => $existingUser->id,
                'role_id' => $staffRole->id,
            ])->exists();

            if ($alreadyStaff) {
                $this->warn("{$email} is already staff at \"{$tenant->name}\".");

                return self::SUCCESS;
            }

            if (! $this->confirm("{$email} already exists. Assign this user as staff at \"{$tenant->name}\"?")) {
                $this->info('Aborted.');

                return self::FAILURE;
            }

            TenantUser::updateOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $existingUser->id],
                ['role_id' => $staffRole->id, 'status' => 'active']
            );

            $this->info("{$email} has been assigned as staff at \"{$tenant->name}\".");

            return self::SUCCESS;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'account_status' => 'active',
            'email_verified_at' => now(),
        ]);

        PlayerStatistic::firstOrCreate(
            ['user_id' => $user->id],
            ['current_rating' => 1200.00]
        );

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $staffRole->id,
            'status' => 'active',
        ]);

        $this->info("Staff account created for {$email} at \"{$tenant->name}\".");

        return self::SUCCESS;
    }
}
