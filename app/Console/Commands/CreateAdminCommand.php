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

#[Signature('admin:create {--name= : Full name of the administrator} {--email= : Email address} {--password= : Password (min 8 characters)}')]
#[Description('Create a new system administrator account or promote an existing user.')]
class CreateAdminCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $adminRole = Role::where('name', 'system_admin')->first();

        if (! $adminRole) {
            $this->error('The system_admin role does not exist. Run php artisan db:seed --class=RolesAndPermissionsSeeder first.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');

        // Validate inputs
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

        // Check if user already exists
        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            return $this->promoteExistingUser($existingUser, $adminRole);
        }

        // Create new admin user
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'account_status' => 'active',
            'email_verified_at' => now(),
        ]);

        PlayerStatistic::firstOrCreate(
            ['user_id' => $user->id],
            ['current_rating' => 1500.00]
        );

        $this->assignAdminRole($user, $adminRole);

        $this->info("Admin account created for {$email}.");

        return self::SUCCESS;
    }

    /**
     * Promote an existing user to system admin.
     */
    private function promoteExistingUser(User $user, Role $adminRole): int
    {
        $systemTenant = Tenant::where('slug', 'platform-admin')->first();

        $alreadyAdmin = $systemTenant && TenantUser::where([
            'tenant_id' => $systemTenant->id,
            'user_id' => $user->id,
            'role_id' => $adminRole->id,
        ])->exists();

        if ($alreadyAdmin) {
            $this->warn("{$user->email} is already a system administrator.");

            return self::SUCCESS;
        }

        if (! $this->confirm("{$user->email} already exists. Promote this user to system admin?")) {
            $this->info('Aborted.');

            return self::FAILURE;
        }

        $this->assignAdminRole($user, $adminRole);

        $this->info("{$user->email} has been promoted to system administrator.");

        return self::SUCCESS;
    }

    /**
     * Assign the system_admin role via the platform-admin tenant.
     */
    private function assignAdminRole(User $user, Role $adminRole): void
    {
        $systemTenant = Tenant::firstOrCreate(
            ['slug' => 'platform-admin'],
            [
                'name' => 'CourtFlex Platform System',
                'business_email' => 'admin@courtflex.test',
                'status' => 'active',
            ]
        );

        TenantUser::firstOrCreate(
            [
                'tenant_id' => $systemTenant->id,
                'user_id' => $user->id,
            ],
            [
                'role_id' => $adminRole->id,
                'status' => 'active',
            ]
        );
    }
}
