<?php

namespace App\Services;

use Illuminate\Http\File as LaravelFile;
use Illuminate\Http\UploadedFile as LaravelUploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

class AwsS3Service
{
    public function uploadFile(
        LaravelUploadedFile|SymfonyUploadedFile|LaravelFile|string $file,
        string $path,
        ?string $filename = null,
        string $disk = 's3',
        string $visibility = 'private'
    ): string {
        if (is_string($file)) {
            $file = new LaravelFile($file);
        }

        if ($file instanceof SymfonyUploadedFile && ! $file instanceof LaravelUploadedFile) {
            $file = LaravelUploadedFile::createFromBase($file, true);
        }

        $name = $filename
            ?: ($file instanceof LaravelUploadedFile
                ? ($file->getClientOriginalName() ?: $file->hashName())
                : basename($file->getPathname()));

        $path = trim($path, '/');

        Storage::disk($disk)->putFileAs($path, $file, $name, [
            'visibility' => $visibility,
        ]);

        return "{$path}/{$name}";
    }

    public function getFileUrl(string $path, string $disk = 's3'): string
    {
        $cdnBaseUrl = config('filesystems.cdn_url');

        if ($cdnBaseUrl) {
            return rtrim($cdnBaseUrl, '/') . '/' . ltrim($path, '/');
        }

        return Storage::disk($disk)->url($path);
    }

    public function deleteFile(string $path, string $disk = 's3'): bool
    {
        return Storage::disk($disk)->delete($path);
    }

    public function fileExists(string $path, string $disk = 's3'): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    public function listFiles(string $directory, string $disk = 's3'): array
    {
        return Storage::disk($disk)->files($directory);
    }

    public function deleteFolder(string $folder, string $disk = 's3'): bool
    {
        $files = Storage::disk($disk)->files($folder);

        if (empty($files)) {
            return true;
        }

        return Storage::disk($disk)->delete($files);
    }
}
