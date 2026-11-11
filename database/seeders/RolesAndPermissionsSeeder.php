<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Tenants
            ['name' => 'tenants.manage', 'label' => 'Manage All Tenants', 'group' => 'tenants'],
            ['name' => 'tenants.view', 'label' => 'View Tenant Details', 'group' => 'tenants'],
            ['name' => 'tenants.update', 'label' => 'Update Tenant Profile', 'group' => 'tenants'],

            // Staff
            ['name' => 'staff.manage', 'label' => 'Manage Facility Staff', 'group' => 'staff'],
            ['name' => 'staff.view', 'label' => 'View Facility Staff', 'group' => 'staff'],

            // Facilities & Courts
            ['name' => 'facilities.manage', 'label' => 'Manage Facilities', 'group' => 'facilities'],
            ['name' => 'facilities.view', 'label' => 'View Facilities', 'group' => 'facilities'],
            ['name' => 'courts.create', 'label' => 'Create Courts', 'group' => 'courts'],
            ['name' => 'courts.update', 'label' => 'Update Courts', 'group' => 'courts'],
            ['name' => 'courts.delete', 'label' => 'Delete Courts', 'group' => 'courts'],
            ['name' => 'courts.view', 'label' => 'View Courts', 'group' => 'courts'],

            // Bookings
            ['name' => 'bookings.create', 'label' => 'Create Bookings', 'group' => 'bookings'],
            ['name' => 'bookings.view', 'label' => 'View Bookings', 'group' => 'bookings'],
            ['name' => 'bookings.manage', 'label' => 'Manage All Facility Bookings', 'group' => 'bookings'],
            ['name' => 'bookings.cancel', 'label' => 'Cancel Bookings', 'group' => 'bookings'],

            // Payments
            ['name' => 'payments.view', 'label' => 'View Payments', 'group' => 'payments'],
            ['name' => 'payments.verify', 'label' => 'Verify Payments', 'group' => 'payments'],
            ['name' => 'payments.refund', 'label' => 'Issue Refunds', 'group' => 'payments'],

            // Clubs
            ['name' => 'clubs.create', 'label' => 'Create Clubs', 'group' => 'clubs'],
            ['name' => 'clubs.manage', 'label' => 'Manage Clubs', 'group' => 'clubs'],
            ['name' => 'clubs.join', 'label' => 'Join Clubs', 'group' => 'clubs'],
            ['name' => 'clubs.view', 'label' => 'View Clubs', 'group' => 'clubs'],

            // Open Play
            ['name' => 'open_play.create', 'label' => 'Create Open Play Sessions', 'group' => 'open_play'],
            ['name' => 'open_play.manage', 'label' => 'Manage Open Play Sessions', 'group' => 'open_play'],
            ['name' => 'open_play.join', 'label' => 'Join Open Play Sessions', 'group' => 'open_play'],

            // Tournaments
            ['name' => 'tournaments.create', 'label' => 'Create Tournaments', 'group' => 'tournaments'],
            ['name' => 'tournaments.manage', 'label' => 'Manage Tournaments', 'group' => 'tournaments'],
            ['name' => 'tournaments.participate', 'label' => 'Participate in Tournaments', 'group' => 'tournaments'],

            // Scores & Matches
            ['name' => 'scores.submit', 'label' => 'Submit Match Scores', 'group' => 'matches'],
            ['name' => 'scores.verify', 'label' => 'Verify Match Scores', 'group' => 'matches'],

            // Administration & Audit
            ['name' => 'audit_logs.view', 'label' => 'View Audit Logs', 'group' => 'admin'],
            ['name' => 'settings.manage', 'label' => 'Manage Platform Settings', 'group' => 'admin'],

            // Database Backups
            ['name' => 'database_backups.view', 'label' => 'View Database Backups', 'group' => 'database_backups'],
            ['name' => 'database_backups.create', 'label' => 'Create Database Backups', 'group' => 'database_backups'],
            ['name' => 'database_backups.delete', 'label' => 'Delete Database Backups', 'group' => 'database_backups'],
            ['name' => 'database_backups.restore', 'label' => 'Restore Database Backups', 'group' => 'database_backups'],
            ['name' => 'database_backups.manage_schedule', 'label' => 'Manage Database Backup Schedules', 'group' => 'database_backups'],

            // Facility payment methods & court front
            ['name' => 'payment_methods.manage', 'label' => 'Manage Facility Payment Methods', 'group' => 'payment_methods'],
            ['name' => 'court_front.manage', 'label' => 'Manage Court Front', 'group' => 'court_front'],
        ];

        $permissionModels = [];
        foreach ($permissions as $p) {
            $permissionModels[$p['name']] = Permission::firstOrCreate(
                ['name' => $p['name']],
                ['label' => $p['label'], 'group' => $p['group']]
            );
        }

        // Roles
        $systemAdmin = Role::firstOrCreate(
            ['name' => 'system_admin'],
            ['label' => 'System Administrator', 'scope' => 'platform', 'description' => 'Platform-wide administrator with full control.']
        );
        $systemAdmin->permissions()->sync(collect($permissionModels)->pluck('id'));

        $owner = Role::firstOrCreate(
            ['name' => 'owner'],
            ['label' => 'Facility Owner', 'scope' => 'tenant', 'description' => 'Facility administrator managing courts, staff, and operations.']
        );
        $ownerPermissions = [
            'tenants.view', 'tenants.update', 'staff.manage', 'staff.view',
            'facilities.manage', 'facilities.view', 'courts.create', 'courts.update', 'courts.delete', 'courts.view',
            'bookings.create', 'bookings.view', 'bookings.manage', 'bookings.cancel',
            'payments.view', 'payments.verify', 'payments.refund',
            'clubs.create', 'clubs.manage', 'clubs.view',
            'open_play.create', 'open_play.manage',
            'tournaments.create', 'tournaments.manage',
            'scores.submit', 'scores.verify',
            'audit_logs.view',
            'payment_methods.manage', 'court_front.manage',
        ];
        $owner->permissions()->sync(
            collect($ownerPermissions)->map(fn ($slug) => $permissionModels[$slug]->id ?? null)->filter()
        );

        $staff = Role::firstOrCreate(
            ['name' => 'staff'],
            ['label' => 'Facility Staff', 'scope' => 'tenant', 'description' => 'Operations staff managing bookings, check-ins, and court facilitation.']
        );
        $staffPermissions = [
            'courts.view', 'bookings.view', 'bookings.manage',
            'payments.view', 'payments.verify',
            'open_play.manage', 'scores.submit', 'scores.verify',
        ];
        $staff->permissions()->sync(
            collect($staffPermissions)->map(fn ($slug) => $permissionModels[$slug]->id ?? null)->filter()
        );

        $player = Role::firstOrCreate(
            ['name' => 'player'],
            ['label' => 'Player', 'scope' => 'platform', 'description' => 'Global user who can book courts, join clubs, and play tournaments.']
        );
        $playerPermissions = [
            'facilities.view', 'courts.view',
            'bookings.create', 'bookings.view', 'bookings.cancel',
            'clubs.view', 'clubs.join',
            'open_play.join', 'tournaments.participate',
        ];
        $player->permissions()->sync(
            collect($playerPermissions)->map(fn ($slug) => $permissionModels[$slug]->id ?? null)->filter()
        );

        $clubManager = Role::firstOrCreate(
            ['name' => 'club_manager'],
            ['label' => 'Club Manager / Organizer', 'scope' => 'tenant', 'description' => 'Delegated organizer managing club activities and matches.']
        );
        $clubManagerPermissions = [
            'clubs.manage', 'tournaments.create', 'tournaments.manage', 'scores.submit',
        ];
        $clubManager->permissions()->sync(
            collect($clubManagerPermissions)->map(fn ($slug) => $permissionModels[$slug]->id ?? null)->filter()
        );
    }
}
