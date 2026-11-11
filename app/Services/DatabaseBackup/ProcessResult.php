<?php

namespace App\Services\DatabaseBackup;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * A short, credential-free description of the failure.
     */
    public function failureMessage(): string
    {
        $detail = trim($this->errorOutput) !== '' ? trim($this->errorOutput) : trim($this->output);

        if (strlen($detail) > 1000) {
            $detail = substr($detail, 0, 1000).'…';
        }

        return $detail !== ''
            ? "Process exited with code {$this->exitCode}: {$detail}"
            : "Process exited with code {$this->exitCode}.";
    }
}
