<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    /**
     * Resolve the configured upload disk (defaults to config('filesystems.upload_disk')).
     */
    public function getDisk(?string $disk = null): string
    {
        return $disk ?: (string) config('filesystems.upload_disk', env('UPLOADS_DISK', env('FILESYSTEM_DISK', 'public')));
    }

    /**
     * Store an uploaded file safely with a random filename on the configured disk.
     */
    public function upload(UploadedFile $file, string $directory = 'uploads', ?string $disk = null): string
    {
        $targetDisk = $this->getDisk($disk);
        $extension = $file->extension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $safeName = Str::random(40).'.'.$extension;

        return $file->storeAs($directory, $safeName, $targetDisk);
    }

    /**
     * Retrieve the public URL for a stored file path on the configured disk.
     */
    public function url(string $path, ?string $disk = null): string
    {
        $targetDisk = $this->getDisk($disk);

        return Storage::disk($targetDisk)->url($path);
    }

    /**
     * Delete a stored file from the configured disk.
     */
    public function delete(?string $path, ?string $disk = null): bool
    {
        if (! $path) {
            return false;
        }

        $targetDisk = $this->getDisk($disk);

        if (Storage::disk($targetDisk)->exists($path)) {
            return Storage::disk($targetDisk)->delete($path);
        }

        return false;
    }

    /**
     * Extract the relative storage path and delete a stored file from an existing URL or path.
     */
    public function deleteFromUrl(?string $urlOrPath, ?string $disk = null): bool
    {
        if (! $urlOrPath) {
            return false;
        }

        $targetDisk = $this->getDisk($disk);
        $path = $this->extractRelativePath($urlOrPath);

        return $this->delete($path, $targetDisk);
    }

    /**
     * Parse relative storage key from a full or relative URL.
     */
    protected function extractRelativePath(string $urlOrPath): string
    {
        if (Str::startsWith($urlOrPath, '/storage/')) {
            return Str::replaceFirst('/storage/', '', $urlOrPath);
        }

        $parsed = parse_url($urlOrPath, PHP_URL_PATH);
        if ($parsed) {
            $trimmed = ltrim($parsed, '/');
            if (Str::startsWith($trimmed, 'storage/')) {
                return Str::replaceFirst('storage/', '', $trimmed);
            }

            return $trimmed;
        }

        return $urlOrPath;
    }
}
