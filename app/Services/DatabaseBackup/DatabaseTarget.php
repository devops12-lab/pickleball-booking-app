<?php

namespace App\Services\DatabaseBackup;

/**
 * Immutable description of the database that is being backed up or restored.
 *
 * The values are always read from Laravel's database configuration; no
 * credentials are ever hardcoded or accepted from an HTTP request.
 */
final class DatabaseTarget
{
    public function __construct(
        public readonly string $engine,
        public readonly string $database,
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly ?string $socket = null,
        public readonly ?string $charset = null,
        public readonly ?string $filePath = null,
    ) {}

    /**
     * Build a target from a Laravel database connection definition.
     */
    public static function fromConnection(?string $connection = null): self
    {
        $connection ??= config('database.default');

        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            throw new BackupException("The database connection [{$connection}] is not configured.");
        }

        $driver = (string) ($config['driver'] ?? '');
        $engine = match ($driver) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql' => 'postgresql',
            'sqlite' => 'sqlite',
            default => throw new BackupException(
                "Database backups support MySQL, MariaDB, PostgreSQL and SQLite. The [{$driver}] driver is not supported."
            ),
        };

        if ($engine === 'sqlite') {
            return self::forSqlite((string) ($config['database'] ?? ''));
        }

        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw new BackupException('The active database connection has no database name configured.');
        }

        return new self(
            engine: $engine,
            database: $database,
            host: $config['host'] ?? null,
            port: isset($config['port']) ? (int) $config['port'] : null,
            username: $config['username'] ?? null,
            password: $config['password'] ?? null,
            socket: $config['socket'] ?? null,
            charset: $config['charset'] ?? null,
        );
    }

    private static function forSqlite(string $database): self
    {
        if ($database === '') {
            throw new BackupException('The SQLite connection has no database path configured.');
        }

        if ($database === ':memory:') {
            return new self(
                engine: 'sqlite',
                database: 'in-memory',
                filePath: null,
            );
        }

        $path = str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $database) === 1
            ? $database
            : base_path($database);

        return new self(
            engine: 'sqlite',
            database: pathinfo($path, PATHINFO_FILENAME),
            filePath: $path,
        );
    }

    /**
     * Determine whether the target can be dumped (in-memory databases cannot).
     */
    public function isBackupable(): bool
    {
        return $this->engine !== 'sqlite' || $this->filePath !== null;
    }

    /**
     * Human readable engine name used in filenames and API responses.
     */
    public function engineLabel(): string
    {
        return match ($this->engine) {
            'mysql' => 'MySQL',
            'postgresql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            default => $this->engine,
        };
    }

    /**
     * File extension for the produced dump.
     */
    public function backupExtension(): string
    {
        return 'sql';
    }
}
