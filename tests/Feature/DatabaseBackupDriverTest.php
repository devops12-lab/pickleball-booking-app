<?php

use App\Services\DatabaseBackup\BackupDriverFactory;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackup\DatabaseTarget;
use App\Services\DatabaseBackup\Drivers\MySqlDumpDriver;
use App\Services\DatabaseBackup\Drivers\PostgreSqlDumpDriver;
use App\Services\DatabaseBackup\Drivers\SqliteDumpDriver;

beforeEach(function (): void {
    config([
        'database-backup.binaries' => [
            'mysqldump' => PHP_BINARY,
            'mysql' => PHP_BINARY,
            'pg_dump' => PHP_BINARY,
            'psql' => PHP_BINARY,
            'sqlite3' => PHP_BINARY,
        ],
    ]);
});

test('a mysql connection is mapped to a mysql target', function (): void {
    config(['database.connections.backup-mysql' => [
        'driver' => 'mysql',
        'host' => 'db.internal',
        'port' => 3307,
        'database' => 'courtflex',
        'username' => 'backup_user',
        'password' => 'top-secret',
        'charset' => 'utf8mb4',
    ]]);

    $target = DatabaseTarget::fromConnection('backup-mysql');

    expect($target->engine)->toBe('mysql')
        ->and($target->database)->toBe('courtflex')
        ->and($target->host)->toBe('db.internal')
        ->and($target->port)->toBe(3307)
        ->and($target->username)->toBe('backup_user')
        ->and($target->password)->toBe('top-secret')
        ->and($target->isBackupable())->toBeTrue();
});

test('a mariadb connection and a postgresql connection are supported', function (): void {
    config(['database.connections.backup-mariadb' => [
        'driver' => 'mariadb',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'mariadb_db',
        'username' => 'root',
    ]]);

    config(['database.connections.backup-pgsql' => [
        'driver' => 'pgsql',
        'host' => 'pg.internal',
        'port' => 5433,
        'database' => 'courtflex_pg',
        'username' => 'pg_user',
        'password' => 'pg-secret',
    ]]);

    expect(DatabaseTarget::fromConnection('backup-mariadb')->engine)->toBe('mysql');

    $postgres = DatabaseTarget::fromConnection('backup-pgsql');

    expect($postgres->engine)->toBe('postgresql')
        ->and($postgres->database)->toBe('courtflex_pg')
        ->and($postgres->port)->toBe(5433)
        ->and($postgres->isBackupable())->toBeTrue();
});

test('an unsupported database driver is rejected', function (): void {
    config(['database.connections.backup-sqlsrv' => [
        'driver' => 'sqlsrv',
        'database' => 'courtflex',
    ]]);

    DatabaseTarget::fromConnection('backup-sqlsrv');
})->throws(BackupException::class);

test('an in-memory sqlite connection cannot be backed up', function (): void {
    config(['database.connections.backup-memory' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]]);

    $target = DatabaseTarget::fromConnection('backup-memory');

    expect($target->engine)->toBe('sqlite')
        ->and($target->database)->toBe('in-memory')
        ->and($target->filePath)->toBeNull()
        ->and($target->isBackupable())->toBeFalse();
});

test('a file based sqlite connection exposes its database file name', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'courtflex-driver-test.sqlite';

    config(['database.connections.backup-sqlite-file' => [
        'driver' => 'sqlite',
        'database' => $path,
    ]]);

    $target = DatabaseTarget::fromConnection('backup-sqlite-file');

    expect($target->engine)->toBe('sqlite')
        ->and($target->database)->toBe('courtflex-driver-test')
        ->and($target->filePath)->toBe($path)
        ->and($target->isBackupable())->toBeTrue();
});

test('the mysql dump command uses native flags and never leaks the password', function (): void {
    $driver = app(MySqlDumpDriver::class);

    $target = new DatabaseTarget(
        engine: 'mysql',
        database: 'courtflex',
        host: '127.0.0.1',
        port: 3306,
        username: 'root',
        password: 'super-secret',
    );

    $command = $driver->dumpCommand($target, 'C:\\backups\\dump.sql');

    expect($command[0])->toBe(PHP_BINARY)
        ->and($command)->toContain('--single-transaction')
        ->and($command)->toContain('--quick')
        ->and($command)->toContain('--routines')
        ->and($command)->toContain('--triggers')
        ->and($command)->toContain('--events')
        ->and($command)->toContain('--hex-blob')
        ->and($command)->toContain('--no-tablespaces')
        ->and($command)->toContain('--default-character-set=utf8mb4')
        ->and($command)->toContain('--result-file=C:\\backups\\dump.sql')
        ->and($command)->toContain('--host=127.0.0.1')
        ->and($command)->toContain('--port=3306')
        ->and($command)->toContain('--user=root')
        ->and($command)->toContain('courtflex')
        ->and(implode(' ', $command))->not->toContain('super-secret')
        ->and($driver->environment($target))->toBe(['MYSQL_PWD' => 'super-secret'])
        ->and($driver->dumpWritesToStdout())->toBeFalse();
});

test('the mysql restore command streams through the mysql client', function (): void {
    $driver = app(MySqlDumpDriver::class);

    $target = new DatabaseTarget(
        engine: 'mysql',
        database: 'courtflex',
        host: 'db.internal',
        port: 3307,
        username: 'root',
        password: 'super-secret',
    );

    $command = $driver->restoreCommand($target);

    expect($command[0])->toBe(PHP_BINARY)
        ->and($command)->toContain('--default-character-set=utf8mb4')
        ->and($command)->toContain('--host=db.internal')
        ->and($command)->toContain('--port=3307')
        ->and($command)->toContain('--user=root')
        ->and($command)->toContain('courtflex')
        ->and(implode(' ', $command))->not->toContain('super-secret');
});

test('a unix socket replaces the mysql host and port', function (): void {
    $driver = app(MySqlDumpDriver::class);

    $target = new DatabaseTarget(
        engine: 'mysql',
        database: 'courtflex',
        host: '127.0.0.1',
        port: 3306,
        username: 'root',
        socket: '/var/run/mysqld/mysqld.sock',
    );

    $command = $driver->dumpCommand($target, '/tmp/dump.sql');

    expect($command)->toContain('--socket=/var/run/mysqld/mysqld.sock')
        ->and($command)->not->toContain('--host=127.0.0.1')
        ->and($command)->not->toContain('--port=3306');
});

test('the postgresql dump command uses native flags and PGPASSWORD', function (): void {
    $driver = app(PostgreSqlDumpDriver::class);

    $target = new DatabaseTarget(
        engine: 'postgresql',
        database: 'courtflex_pg',
        host: 'pg.internal',
        port: 5432,
        username: 'pg_user',
        password: 'pg-secret',
    );

    $command = $driver->dumpCommand($target, '/tmp/dump.sql');

    expect($command[0])->toBe(PHP_BINARY)
        ->and($command)->toContain('--format=plain')
        ->and($command)->toContain('--no-owner')
        ->and($command)->toContain('--no-privileges')
        ->and($command)->toContain('--if-exists')
        ->and($command)->toContain('--clean')
        ->and($command)->toContain('--file=/tmp/dump.sql')
        ->and($command)->toContain('--host=pg.internal')
        ->and($command)->toContain('--port=5432')
        ->and($command)->toContain('--username=pg_user')
        ->and($command)->toContain('courtflex_pg')
        ->and(implode(' ', $command))->not->toContain('pg-secret')
        ->and($driver->environment($target))->toBe(['PGPASSWORD' => 'pg-secret'])
        ->and($driver->dumpWritesToStdout())->toBeFalse();
});

test('the postgresql restore command stops on the first error', function (): void {
    $driver = app(PostgreSqlDumpDriver::class);

    $target = new DatabaseTarget(
        engine: 'postgresql',
        database: 'courtflex_pg',
        host: 'pg.internal',
        port: 5432,
        username: 'pg_user',
        password: 'pg-secret',
    );

    $command = $driver->restoreCommand($target);

    expect($command[0])->toBe(PHP_BINARY)
        ->and($command)->toContain('--no-psqlrc')
        ->and($command)->toContain('--set=ON_ERROR_STOP=on')
        ->and($command)->toContain('--host=pg.internal')
        ->and($command)->toContain('--port=5432')
        ->and($command)->toContain('--username=pg_user')
        ->and($command)->toContain('--dbname=courtflex_pg');
});

test('the sqlite driver dumps through the sqlite3 shell', function (): void {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'courtflex-sqlite-driver.sqlite';

    if (! is_file($path)) {
        file_put_contents($path, '');
    }

    $driver = app(SqliteDumpDriver::class);

    $target = new DatabaseTarget(
        engine: 'sqlite',
        database: 'courtflex-sqlite-driver',
        filePath: $path,
    );

    $dumpCommand = $driver->dumpCommand($target, '/tmp/dump.sql');
    $restoreCommand = $driver->restoreCommand($target);

    expect($dumpCommand)->toBe([PHP_BINARY, $path, '.dump'])
        ->and($restoreCommand)->toBe([PHP_BINARY, $path])
        ->and($driver->dumpWritesToStdout())->toBeTrue()
        ->and($driver->environment($target))->toBe([]);

    @unlink($path);
});

test('the sqlite driver refuses in-memory databases', function (): void {
    $driver = app(SqliteDumpDriver::class);

    $target = new DatabaseTarget(engine: 'sqlite', database: 'in-memory', filePath: null);

    expect(fn () => $driver->dumpCommand($target, '/tmp/dump.sql'))
        ->toThrow(BackupException::class);
});

test('the driver factory rejects unknown engines', function (): void {
    app(BackupDriverFactory::class)->forDriver('sqlsrv');
})->throws(BackupException::class);
