<?php

namespace App\Services\DatabaseBackup;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Thin, memory safe wrapper around Symfony Process.
 *
 * Commands are always executed as argument arrays (never through a shell) so
 * user supplied values can never be interpreted as shell syntax. Standard
 * output can be streamed straight to a file, which keeps large dumps out of
 * PHP memory.
 */
class ProcessRunner
{
    /**
     * Execute a command and optionally stream its output to a file.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function run(
        array $command,
        array $environment = [],
        ?string $stdinPath = null,
        ?string $stdoutPath = null,
        ?int $timeout = null,
    ): ProcessResult {
        $timeout ??= (int) config('database-backup.timeouts.backup', 3600);

        if ($stdoutPath !== null) {
            $this->ensureDirectoryExists(dirname($stdoutPath));
        }

        $process = new Process($command, base_path(), $environment, null, $timeout);
        $process->setTimeout($timeout);

        $stdinHandle = null;
        $stdoutHandle = null;

        if ($stdinPath !== null) {
            $stdinHandle = @fopen($stdinPath, 'rb');

            if ($stdinHandle === false) {
                throw new BackupException('The SQL file could not be opened for reading.');
            }

            $process->setInput($stdinHandle);
        }

        if ($stdoutPath !== null) {
            $stdoutHandle = @fopen($stdoutPath, 'wb');

            if ($stdoutHandle === false) {
                throw new BackupException('The backup file could not be opened for writing.');
            }
        }

        $output = '';
        $errorOutput = '';

        try {
            $process->start(function (string $type, string $buffer) use ($stdoutHandle, &$output, &$errorOutput): void {
                if ($type === Process::OUT) {
                    if ($stdoutHandle !== null) {
                        if (fwrite($stdoutHandle, $buffer) === false) {
                            throw new BackupException('Writing the backup file failed. Check the available disk space.');
                        }
                    } elseif (strlen($output) < 65536) {
                        $output .= $buffer;
                    }

                    return;
                }

                if (strlen($errorOutput) < 65536) {
                    $errorOutput .= $buffer;
                }
            });

            $process->wait();
        } catch (ProcessTimedOutException) {
            $process->stop(1);

            throw new BackupException("The database process exceeded the {$timeout} second timeout and was stopped.");
        } catch (BackupException $exception) {
            $process->stop(1);

            throw $exception;
        } catch (Throwable $exception) {
            $process->stop(1);

            throw new BackupException('The database process could not be executed: '.$exception->getMessage());
        } finally {
            if (is_resource($stdoutHandle)) {
                fclose($stdoutHandle);
            }

            if (is_resource($stdinHandle)) {
                fclose($stdinHandle);
            }
        }

        return new ProcessResult(
            exitCode: (int) $process->getExitCode(),
            output: $output,
            errorOutput: $errorOutput,
        );
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new BackupException('The backup directory could not be created.');
        }
    }
}
