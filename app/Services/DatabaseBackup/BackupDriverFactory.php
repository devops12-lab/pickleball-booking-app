<?php

namespace App\Services\DatabaseBackup;

use App\Services\DatabaseBackup\Contracts\DatabaseDumpDriver;
use App\Services\DatabaseBackup\Drivers\MySqlDumpDriver;
use App\Services\DatabaseBackup\Drivers\PostgreSqlDumpDriver;
use App\Services\DatabaseBackup\Drivers\SqliteDumpDriver;

class BackupDriverFactory
{
    public function __construct(
        protected MySqlDumpDriver $mysql,
        protected PostgreSqlDumpDriver $postgresql,
        protected SqliteDumpDriver $sqlite,
    ) {}

    /**
     * Resolve the dump driver for an engine name.
     */
    public function forDriver(string $engine): DatabaseDumpDriver
    {
        return match ($engine) {
            'mysql' => $this->mysql,
            'postgresql' => $this->postgresql,
            'sqlite' => $this->sqlite,
            default => throw new BackupException("No backup driver is registered for the [{$engine}] database engine."),
        };
    }

    /**
     * Resolve the dump driver for the active application connection.
     */
    public function forActiveConnection(): DatabaseDumpDriver
    {
        return $this->forDriver(DatabaseTarget::fromConnection()->engine);
    }

    /**
     * @return list<DatabaseDumpDriver>
     */
    public function all(): array
    {
        return [$this->mysql, $this->postgresql, $this->sqlite];
    }
}
