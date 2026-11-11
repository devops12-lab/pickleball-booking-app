<?php

namespace App\Policies;

use App\Models\DatabaseBackup;
use App\Models\User;

/**
 * Authorization for the database backup and restore feature.
 *
 * System administrators pass every check through User::hasPermission(), but
 * the explicit permission names keep the policy usable for delegated roles.
 */
class DatabaseBackupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('database_backups.view');
    }

    public function view(User $user, DatabaseBackup $backup): bool
    {
        return $user->hasPermission('database_backups.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('database_backups.create');
    }

    public function delete(User $user, DatabaseBackup $backup): bool
    {
        return $user->hasPermission('database_backups.delete');
    }

    /**
     * Authorize a restore. The backup is optional so the ability can also be
     * checked against the class before a record exists (upload + restore).
     */
    public function restore(User $user, ?DatabaseBackup $backup = null): bool
    {
        return $user->hasPermission('database_backups.restore');
    }

    public function manageSchedule(User $user): bool
    {
        return $user->hasPermission('database_backups.manage_schedule');
    }
}
