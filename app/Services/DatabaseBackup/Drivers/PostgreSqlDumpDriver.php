<?php

namespace App\Services\DatabaseBackup\Drivers;

use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackup\BinaryResolver;
use App\Services\DatabaseBackup\Contracts\DatabaseDumpDriver;
use App\Services\DatabaseBackup\DatabaseTarget;

class PostgreSqlDumpDriver implements DatabaseDumpDriver
{
    public function __construct(
        protected BinaryResolver $binaries
    ) {}

    public function engine(): string
    {
        return 'postgresql';
    }

    public function dumpCommand(DatabaseTarget $target, string $outputPath): array
    {
        $command = [
            $this->binaries->resolve($this->configuredBinary('pg_dump')),
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            '--if-exists',
            '--clean',
            '--file='.$outputPath,
            '--host='.($target->host ?: '127.0.0.1'),
        ];

        if ($target->port !== null) {
            $command[] = '--port='.$target->port;
        }

        if (filled($target->username)) {
            $command[] = '--username='.$target->username;
        }

        $command[] = $target->database;

        return $command;
    }

    public function dumpWritesToStdout(): bool
    {
        return false;
    }

    public function restoreCommand(DatabaseTarget $target): array
    {
        $command = [
            $this->binaries->resolve($this->configuredBinary('psql')),
            '--no-psqlrc',
            '--set=ON_ERROR_STOP=on',
            '--host='.($target->host ?: '127.0.0.1'),
        ];

        if ($target->port !== null) {
            $command[] = '--port='.$target->port;
        }

        if (filled($target->username)) {
            $command[] = '--username='.$target->username;
        }

        $command[] = '--dbname='.$target->database;

        return $command;
    }

    public function environment(DatabaseTarget $target): array
    {
        if (! filled($target->password)) {
            return [];
        }

        return ['PGPASSWORD' => (string) $target->password];
    }

    public function binaries(): array
    {
        return [
            'pg_dump' => $this->configuredBinary('pg_dump'),
            'psql' => $this->configuredBinary('psql'),
        ];
    }

    public function verify(): void
    {
        foreach ($this->binaries() as $name => $binary) {
            if (! $this->binaries->exists($binary)) {
                throw new BackupException(
                    "The PostgreSQL utility [{$name}] is not available. Install the PostgreSQL client tools or configure DB_BACKUP_".strtoupper($name).'_PATH.'
                );
            }
        }
    }

    private function configuredBinary(string $name): string
    {
        return (string) config("database-backup.binaries.{$name}", $name);
    }
}
