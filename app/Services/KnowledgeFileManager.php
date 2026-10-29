<?php

namespace App\Services;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KnowledgeFileManager
{
    private const DISK = 'local';

    /**
     * @return array{path: string, sha256: string}
     */
    public function store(User $user, UploadedFile $file): array
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file->getClientOriginalName()));
        $path = $file->storeAs(
            'knowledge/'.$user->id,
            Str::uuid().'_'.$safeName,
            self::DISK,
        );
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The uploaded file could not be stored securely.');
        }

        $sha256 = hash_file('sha256', $file->getRealPath());
        if (! is_string($sha256)) {
            Storage::disk(self::DISK)->delete($path);
            throw new RuntimeException('The uploaded file could not be verified.');
        }

        return ['path' => $path, 'sha256' => $sha256];
    }

    public function delete(KnowledgeDocument $document): void
    {
        $disk = Storage::disk(self::DISK);
        if ($disk->exists($document->disk_path) && ! $disk->delete($document->disk_path)) {
            throw new RuntimeException('The private file could not be removed.');
        }
    }

    public function download(KnowledgeDocument $document): StreamedResponse
    {
        return Storage::disk(self::DISK)->download(
            $document->disk_path,
            basename($document->original_name),
        );
    }

    public function absolutePath(KnowledgeDocument $document): string
    {
        return Storage::disk(self::DISK)->path($document->disk_path);
    }
}
