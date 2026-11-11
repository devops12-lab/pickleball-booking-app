<?php

namespace App\Services\DatabaseBackup\Contracts;

use App\Services\DatabaseBackup\DatabaseTarget;

interface DatabaseDumpDriver
{
    /**
     * Canonical engine name (mysql, postgresql or sqlite).
     */
    public function engine(): string;

    /**
     * The command that produces the dump.
     *
     * @return list<string>
     */
    public function dumpCommand(DatabaseTarget $target, string $outputPath): array;

    /**
     * Determine whether the dump is written to standard output (and therefore
     * has to be streamed to the output file by the process runner).
     */
    public function dumpWritesToStdout(): bool;

    /**
     * The command that imports a dump from standard input.
     *
     * @return list<string>
     */
    public function restoreCommand(DatabaseTarget $target): array;

    /**
     * Environment variables carrying credentials (never command line flags).
     *
     * @return array<string, string>
     */
    public function environment(DatabaseTarget $target): array;

    /**
     * Configured binaries required by this driver.
     *
     * @return array<string, string> keyed by configuration key suffix
     */
    public function binaries(): array;

    /**
     * Verify every required binary exists, throwing a descriptive exception.
     */
    public function verify(): void;
}
