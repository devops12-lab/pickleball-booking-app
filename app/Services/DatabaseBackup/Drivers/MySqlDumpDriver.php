<?php

namespace App\Services\DatabaseBackup\Drivers;

use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackup\BinaryResolver;
use App\Services\DatabaseBackup\Contracts\DatabaseDumpDriver;
use App\Services\DatabaseBackup\DatabaseTarget;

class MySqlDumpDriver implements DatabaseDumpDriver
{
    public function __construct(
        protected BinaryResolver $binaries
    ) {}

    public function engine(): string
    {
        return 'mysql';
    }

    public function dumpCommand(DatabaseTarget $target, string $outputPath): array
    {
        $command = [
            $this->binaries->resolve($this->configuredBinary('mysqldump')),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
        ];

        if (filled($target->socket)) {
            $command[] = '--socket='.$target->socket;
        } else {
            $command[] = '--host='.($target->host ?: '127.0.0.1');

            if ($target->port !== null) {
                $command[] = '--port='.$target->port;
            }
        }

        if (filled($target->username)) {
            $command[] = '--user='.$target->username;
        }

        $command[] = '--result-file='.$outputPath;
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
            $this->binaries->resolve($this->configuredBinary('mysql')),
            '--default-character-set=utf8mb4',
        ];

        if (filled($target->socket)) {
            $command[] = '--socket='.$target->socket;
        } else {
            $command[] = '--host='.($target->host ?: '127.0.0.1');

            if ($target->port !== null) {
                $command[] = '--port='.$target->port;
            }
        }

        if (filled($target->username)) {
            $command[] = '--user='.$target->username;
        }

        $command[] = $target->database;

        return $command;
    }

    public function environment(DatabaseTarget $target): array
    {
        if (! filled($target->password)) {
            return [];
        }

        return ['MYSQL_PWD' => (string) $target->password];
    }

    public function binaries(): array
    {
        return [
            'mysqldump' => $this->configuredBinary('mysqldump'),
            'mysql' => $this->configuredBinary('mysql'),
        ];
    }

    public function verify(): void
    {
        foreach ($this->binaries() as $name => $binary) {
            if (! $this->binaries->exists($binary)) {
                throw new BackupException(
                    "The MySQL utility [{$name}] is not available. Install the MySQL client tools or configure DB_BACKUP_".strtoupper($name).'_PATH.'
                );
            }
        }
    }

    private function configuredBinary(string $name): string
    {
        return (string) config("database-backup.binaries.{$name}", $name);
    }
}
