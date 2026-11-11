<?php

namespace App\Services\DatabaseBackup\Drivers;

use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackup\BinaryResolver;
use App\Services\DatabaseBackup\Contracts\DatabaseDumpDriver;
use App\Services\DatabaseBackup\DatabaseTarget;

/**
 * SQLite support keeps the same plain .sql, stdin based contract as MySQL and
 * PostgreSQL by delegating to the official sqlite3 shell (.dump).
 */
class SqliteDumpDriver implements DatabaseDumpDriver
{
    public function __construct(
        protected BinaryResolver $binaries
    ) {}

    public function engine(): string
    {
        return 'sqlite';
    }

    public function dumpCommand(DatabaseTarget $target, string $outputPath): array
    {
        return [
            $this->binaries->resolve($this->configuredBinary('sqlite3')),
            $this->databaseFile($target),
            '.dump',
        ];
    }

    public function dumpWritesToStdout(): bool
    {
        return true;
    }

    public function restoreCommand(DatabaseTarget $target): array
    {
        return [
            $this->binaries->resolve($this->configuredBinary('sqlite3')),
            $this->databaseFile($target),
        ];
    }

    public function environment(DatabaseTarget $target): array
    {
        return [];
    }

    public function binaries(): array
    {
        return [
            'sqlite3' => $this->configuredBinary('sqlite3'),
        ];
    }

    public function verify(): void
    {
        foreach ($this->binaries() as $name => $binary) {
            if (! $this->binaries->exists($binary)) {
                throw new BackupException(
                    "The SQLite utility [{$name}] is not available. Install the SQLite shell or configure DB_BACKUP_SQLITE3_PATH."
                );
            }
        }
    }

    private function databaseFile(DatabaseTarget $target): string
    {
        if (blank($target->filePath)) {
            throw new BackupException(
                'In-memory SQLite databases cannot be backed up or restored. Configure a file based SQLite database or use MySQL / PostgreSQL.'
            );
        }

        if (! is_file($target->filePath)) {
            throw new BackupException('The SQLite database file could not be found.');
        }

        return $target->filePath;
    }

    private function configuredBinary(string $name): string
    {
        return (string) config("database-backup.binaries.{$name}", $name);
    }
}
