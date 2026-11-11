<?php

namespace App\Services\DatabaseBackup;

/**
 * Resolves an executable to an absolute path without invoking a shell.
 */
class BinaryResolver
{
    /**
     * Resolve a configured binary, throwing a descriptive error when missing.
     */
    public function resolve(string $binary): string
    {
        $binary = trim($binary);

        if ($binary === '') {
            throw new BackupException('No executable path was configured for a required database utility.');
        }

        if ($this->looksLikePath($binary)) {
            if ($this->isExecutable($binary)) {
                return $binary;
            }

            throw new BackupException("The configured database utility [{$binary}] does not exist or is not executable.");
        }

        $extensions = $this->executableExtensions();

        foreach ($this->searchPaths() as $directory) {
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$binary.$extension;

                if ($this->isExecutable($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new BackupException(
            "The database utility [{$binary}] could not be found. Install it and/or set its full path in the database backup configuration."
        );
    }

    /**
     * Determine whether the binary can be resolved.
     */
    public function exists(string $binary): bool
    {
        try {
            $this->resolve($binary);

            return true;
        } catch (BackupException) {
            return false;
        }
    }

    /**
     * The absolute path when resolvable, otherwise null.
     */
    public function pathOrNull(string $binary): ?string
    {
        try {
            return $this->resolve($binary);
        } catch (BackupException) {
            return null;
        }
    }

    private function looksLikePath(string $binary): bool
    {
        return str_contains($binary, '/') || str_contains($binary, '\\');
    }

    private function isExecutable(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        return DIRECTORY_SEPARATOR === '\\' || is_executable($path);
    }

    /**
     * @return list<string>
     */
    private function executableExtensions(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [''];
        }

        $extensions = [''];
        $pathext = (string) getenv('PATHEXT');

        foreach (explode(';', $pathext) as $extension) {
            $extension = strtolower(trim($extension));

            if ($extension !== '' && ! in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }

    /**
     * @return list<string>
     */
    private function searchPaths(): array
    {
        $paths = array_filter(
            explode(PATH_SEPARATOR, (string) getenv('PATH')),
            static fn (string $path): bool => trim($path) !== ''
        );

        return array_values($paths);
    }
}
