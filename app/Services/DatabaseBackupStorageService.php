<?php

namespace App\Services;

use App\Enums\BackupType;
use App\Services\DatabaseBackup\BackupException;
use App\Services\DatabaseBackup\DatabaseTarget;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Owns every path and file operation for database backups.
 *
 * All files live inside the configured private disk path. Every externally
 * supplied value is reduced to a basename before it touches the filesystem,
 * which makes path traversal impossible.
 */
class DatabaseBackupStorageService
{
    public function diskName(): string
    {
        return (string) config('database-backup.disk', 'local');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function directory(): string
    {
        return trim((string) config('database-backup.path', 'database-backups'), '/');
    }

    /**
     * Whether the configured disk is stored on the local filesystem.
     */
    public function isLocal(): bool
    {
        return (string) config("filesystems.disks.{$this->diskName()}.driver") === 'local';
    }

    /**
     * Build a safe, human readable filename.
     */
    public function generateFilename(DatabaseTarget $target, BackupType $type, ?string $extension = null): string
    {
        $extension = $extension ?? $target->backupExtension();

        $base = sprintf(
            'database_backup_%s_%s_%s',
            $target->engine,
            $type->value,
            now()->format('Y-m-d_H-i-s')
        );

        $filename = "{$base}.{$extension}";
        $suffix = 1;

        while ($this->disk()->exists($this->directory().'/'.$filename)) {
            $filename = "{$base}_{$suffix}.{$extension}";
            $suffix++;
        }

        return $filename;
    }

    /**
     * Combine the configured directory with a validated basename.
     */
    public function relativePath(string $filename): string
    {
        return $this->directory().'/'.$this->safeFilename($filename);
    }

    /**
     * Validate a stored relative path and return it unchanged.
     */
    public function validateStoredPath(string $storedPath): string
    {
        $storedPath = str_replace('\\', '/', $storedPath);
        $prefix = $this->directory().'/';

        if (! str_starts_with($storedPath, $prefix)) {
            throw new BackupException('The backup file path is outside the configured backup directory.');
        }

        $filename = substr($storedPath, strlen($prefix));

        if ($filename === '' || str_contains($filename, '/')) {
            throw new BackupException('The backup file path is invalid.');
        }

        $this->safeFilename($filename);

        return $storedPath;
    }

    /**
     * Ensure the private backup directory exists.
     */
    public function ensureDirectoryExists(): void
    {
        $this->disk()->makeDirectory($this->directory());
    }

    /**
     * Absolute path for a stored file, only possible on the local disk.
     */
    public function absolutePath(string $relativePath): ?string
    {
        if (! $this->isLocal()) {
            return null;
        }

        $this->validateStoredPath($relativePath);

        return $this->disk()->path($relativePath);
    }

    /**
     * Temporary local path used while a dump is being produced.
     */
    public function stagingPath(string $filename): string
    {
        $directory = storage_path('app/'.trim((string) config('database-backup.temporary_path', 'database-backups-tmp'), '/'));

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new BackupException('The temporary backup directory could not be created.');
        }

        return $directory.DIRECTORY_SEPARATOR.$this->safeFilename($filename);
    }

    /**
     * Copy a local file onto the configured disk without loading it in memory.
     */
    public function putFromPath(string $storedPath, string $localPath): void
    {
        $storedPath = $this->validateStoredPath($storedPath);
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new BackupException('The generated backup file could not be read.');
        }

        try {
            if (! $this->disk()->put($storedPath, $stream)) {
                throw new BackupException('The backup file could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Download a stored file to a temporary local path for native tools.
     *
     * @return array{path: string, cleanup: bool}
     */
    public function localCopy(string $storedPath, string $filename): array
    {
        $storedPath = $this->validateStoredPath($storedPath);
        $absolute = $this->absolutePath($storedPath);

        if ($absolute !== null) {
            return ['path' => $absolute, 'cleanup' => false];
        }

        $temporary = $this->stagingPath($filename);

        if (! $this->copyToLocalFile($storedPath, $temporary)) {
            throw new BackupException('The backup file could not be downloaded for restoration.');
        }

        return ['path' => $temporary, 'cleanup' => true];
    }

    public function exists(string $storedPath): bool
    {
        return $this->disk()->exists($this->validateStoredPath($storedPath));
    }

    public function size(string $storedPath): int
    {
        return (int) $this->disk()->size($this->validateStoredPath($storedPath));
    }

    /**
     * SHA-256 checksum of a stored file, computed as a stream.
     */
    public function checksum(string $storedPath): ?string
    {
        $storedPath = $this->validateStoredPath($storedPath);
        $absolute = $this->absolutePath($storedPath);

        if ($absolute !== null) {
            return is_file($absolute) ? (hash_file('sha256', $absolute) ?: null) : null;
        }

        $stream = $this->disk()->readStream($storedPath);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Remove a stored backup file.
     */
    public function delete(string $storedPath): bool
    {
        return $this->disk()->delete($this->validateStoredPath($storedPath));
    }

    /**
     * Open a read stream for an authenticated download.
     *
     * @return resource
     */
    public function readStream(string $storedPath)
    {
        $stream = $this->disk()->readStream($this->validateStoredPath($storedPath));

        if (! is_resource($stream)) {
            throw new BackupException('The backup file could not be opened.');
        }

        return $stream;
    }

    public function mimeType(): string
    {
        return 'application/sql';
    }

    /**
     * Detect the database engine from the header of a SQL file.
     */
    public function detectEngine(string $absolutePath): ?string
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $header = (string) fread($handle, 8192);
        } finally {
            fclose($handle);
        }

        return match (true) {
            str_contains($header, '-- MySQL dump'),
            str_contains($header, 'MySQL dump'),
            str_contains($header, '/*!40101'),
            str_contains($header, '/*!40103') => 'mysql',
            str_contains($header, '-- PostgreSQL database dump'),
            str_contains($header, 'pg_catalog'),
            str_contains($header, 'PostgreSQL database cluster dump') => 'postgresql',
            str_starts_with($header, 'PRAGMA foreign_keys=OFF;'),
            str_contains($header, 'sqlite_sequence'),
            str_contains($header, 'SQLite format 3') => 'sqlite',
            default => null,
        };
    }

    /**
     * Reject anything that is not a plain file name with an allowed extension.
     */
    public function safeFilename(string $filename): string
    {
        $filename = trim($filename);

        if ($filename === '' || $filename !== basename($filename) || str_contains($filename, '..')) {
            throw new BackupException('The backup filename is invalid.');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $filename) !== 1) {
            throw new BackupException('The backup filename contains unsupported characters.');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowed = (array) config('database-backup.allowed_extensions', ['sql']);

        if (! in_array($extension, $allowed, true)) {
            throw new BackupException('Only .sql backup files are supported.');
        }

        return $filename;
    }

    /**
     * Copy a stored file to a local path in chunks.
     */
    private function copyToLocalFile(string $storedPath, string $localPath): bool
    {
        $source = $this->disk()->readStream($storedPath);
        $target = @fopen($localPath, 'wb');

        if (! is_resource($source) || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            return false;
        }

        try {
            $copied = stream_copy_to_stream($source, $target);

            return $copied !== false;
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    /**
     * Random name helper used for temporary uploads.
     */
    public function randomSqlFilename(): string
    {
        return 'database_backup_upload_'.Str::random(20).'.sql';
    }
}
