<?php

namespace App\Console;

use RuntimeException;

final class LocalConnectorFileService
{
    private const MAX_FILE_BYTES = 1_048_576;

    public function __construct(
        private readonly LocalConnectorPolicy $policy,
    ) {}

    /**
     * @return array{path: string, bytes: int, content?: string, simulated: bool}
     */
    public function read(string $path, bool $live): array
    {
        $this->policy->assertCapabilityEnabled('file_read');
        $resolved = $this->policy->assertAllowedPath($path);
        $this->assertRegularFile($resolved);
        if (! $live) {
            $this->policy->log('file.read', $resolved, 'sandbox', 'simulated');

            return ['path' => $resolved, 'bytes' => (int) filesize($resolved), 'simulated' => true];
        }

        $contents = file_get_contents($resolved);
        if ($contents === false) {
            throw new RuntimeException('The local connector could not read this file.');
        }
        if (str_contains($contents, "\0")) {
            throw new RuntimeException('Binary files cannot be read through the local connector.');
        }
        $this->policy->log('file.read', $resolved, 'live', 'completed');

        return [
            'path' => $resolved,
            'bytes' => strlen($contents),
            'content' => $contents,
            'simulated' => false,
        ];
    }

    /**
     * @return array{path: string, diff: string, simulated: bool, checkpoint: ?string, base_sha256: string}
     */
    public function write(string $path, string $content, bool $live, ?string $expectedBaseHash = null): array
    {
        $this->policy->assertCapabilityEnabled('file_write');
        if (is_link($path)) {
            throw new RuntimeException('The local connector cannot write through a symbolic link.');
        }
        $resolved = $this->policy->assertAllowedPath($path, file_exists($path));
        if (strlen($content) > self::MAX_FILE_BYTES || str_contains($content, "\0")) {
            throw new RuntimeException('Connector writes are limited to text files up to 1 MiB.');
        }
        if (is_dir($resolved)) {
            throw new RuntimeException('The local connector cannot replace a directory with a file.');
        }

        $existed = file_exists($resolved);
        $previous = $existed ? file_get_contents($resolved) : '';
        if ($previous === false) {
            throw new RuntimeException('The local connector could not read the file before preparing its diff.');
        }
        if (strlen($previous) > self::MAX_FILE_BYTES || str_contains($previous, "\0")) {
            throw new RuntimeException('The local connector will not overwrite a binary or oversized file.');
        }

        $diff = $this->unifiedDiff($previous, $content);
        $baseHash = hash('sha256', ($existed ? 'file:' : 'missing:').hash('sha256', $previous));
        if (! $live) {
            $this->policy->log('file.write', $resolved, 'sandbox', 'simulated');

            return [
                'path' => $resolved,
                'diff' => $diff,
                'simulated' => true,
                'checkpoint' => null,
                'base_sha256' => $baseHash,
            ];
        }

        $this->policy->assertLiveMode(true);
        if (! is_string($expectedBaseHash) || ! hash_equals($baseHash, $expectedBaseHash)) {
            throw new RuntimeException('The file changed after the diff was reviewed. Preview it again before applying.');
        }
        $checkpointId = null;
        if ($existed) {
            $checkpointId = $this->checkpoint($resolved);
        }
        $temporary = dirname($resolved).DIRECTORY_SEPARATOR.'.multi-ai-agent-'.bin2hex(random_bytes(12)).'.tmp';
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('The local connector could not write the prepared file.');
        }
        $originalRemoved = false;
        if (DIRECTORY_SEPARATOR === '\\' && $existed) {
            if (! unlink($resolved)) {
                if (! unlink($temporary)) {
                    throw new RuntimeException('The original file could not be replaced and the temporary file could not be removed.');
                }
                throw new RuntimeException('The local connector could not prepare the Windows file replacement.');
            }
            $originalRemoved = true;
        }
        if (! rename($temporary, $resolved)) {
            if (! unlink($temporary)) {
                throw new RuntimeException('Could not activate the file update or remove its temporary file.');
            }
            if ($originalRemoved && $checkpointId !== null) {
                $checkpointPath = $this->policy->storageDirectory('checkpoints').DIRECTORY_SEPARATOR.$checkpointId.'.bin';
                if (! copy($checkpointPath, $resolved)) {
                    throw new RuntimeException('File update failed and the original could not be restored from its checkpoint.');
                }
            }
            throw new RuntimeException('The local connector could not atomically replace the file.');
        }
        $this->policy->log('file.write', $resolved, 'live', 'completed');

        return [
            'path' => $resolved,
            'diff' => $diff,
            'simulated' => false,
            'checkpoint' => $checkpointId,
            'base_sha256' => $baseHash,
        ];
    }

    /**
     * @return array{id: string, original_path: string, simulated: bool}
     */
    public function trash(string $path, bool $live): array
    {
        $this->policy->assertCapabilityEnabled('file_write');
        $resolved = $this->policy->assertAllowedPath($path);
        $this->assertRegularFile($resolved);
        $trashId = bin2hex(random_bytes(16));
        if (! $live) {
            $this->policy->log('file.trash', $resolved, 'sandbox', 'simulated');

            return ['id' => $trashId, 'original_path' => $resolved, 'simulated' => true];
        }
        $this->policy->assertLiveMode(true);

        $trashDirectory = $this->policy->storageDirectory('trash');
        $trashPath = $trashDirectory.DIRECTORY_SEPARATOR.$trashId.'.bin';
        $metadataPath = $trashDirectory.DIRECTORY_SEPARATOR.$trashId.'.json';
        $contentsHash = hash_file('sha256', $resolved);
        if (! is_string($contentsHash) || ! copy($resolved, $trashPath)) {
            throw new RuntimeException('The local connector could not stage this file in Trash.');
        }
        if (! hash_equals($contentsHash, (string) hash_file('sha256', $trashPath))) {
            if (! unlink($trashPath)) {
                throw new RuntimeException('Trash verification failed and the staged copy could not be removed.');
            }
            throw new RuntimeException('The local connector Trash copy failed integrity verification.');
        }
        $metadata = json_encode([
            'original_path' => $resolved,
            'trashed_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($metadataPath, $metadata, LOCK_EX) === false) {
            if (! unlink($trashPath)) {
                throw new RuntimeException('Trash metadata could not be written and the staged copy could not be removed.');
            }
            throw new RuntimeException('The local connector could not record Trash metadata.');
        }
        if (! unlink($resolved)) {
            if (! unlink($trashPath) || ! unlink($metadataPath)) {
                throw new RuntimeException('The file could not be moved to Trash and temporary copies could not be removed.');
            }
            throw new RuntimeException('The original file could not be removed after it was safely copied to Trash.');
        }
        $this->policy->log('file.trash', $resolved, 'live', 'completed');

        return ['id' => $trashId, 'original_path' => $resolved, 'simulated' => false];
    }

    /**
     * @return array{path: string, restored: bool}
     */
    public function restore(string $trashId): array
    {
        $this->policy->assertCapabilityEnabled('file_write');
        $this->policy->assertLiveMode(true);
        if (preg_match('/^[a-f0-9]{32}$/', $trashId) !== 1) {
            throw new RuntimeException('A valid Trash item identifier is required.');
        }
        $directory = $this->policy->storageDirectory('trash');
        $dataPath = $directory.DIRECTORY_SEPARATOR.$trashId.'.bin';
        $metadataPath = $directory.DIRECTORY_SEPARATOR.$trashId.'.json';
        if (! is_file($dataPath) || ! is_file($metadataPath)) {
            throw new RuntimeException('The requested Trash item is unavailable.');
        }
        $metadata = json_decode((string) file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
        $path = $metadata['original_path'] ?? null;
        if (! is_string($path)) {
            throw new RuntimeException('The Trash item has invalid restore metadata.');
        }
        $resolved = $this->policy->assertAllowedPath($path, false);
        if (file_exists($resolved)) {
            throw new RuntimeException('The original path already exists; refusing to overwrite it during restore.');
        }
        if (! copy($dataPath, $resolved)) {
            throw new RuntimeException('The Trash item could not be restored.');
        }
        if (! unlink($dataPath) || ! unlink($metadataPath)) {
            throw new RuntimeException('The file was restored, but the Trash metadata could not be cleaned up.');
        }
        $this->policy->log('file.restore', $resolved, 'live', 'completed');

        return ['path' => $resolved, 'restored' => true];
    }

    private function checkpoint(string $path): string
    {
        $id = bin2hex(random_bytes(16));
        $checkpointPath = $this->policy->storageDirectory('checkpoints').DIRECTORY_SEPARATOR.$id.'.bin';
        $metadataPath = $this->policy->storageDirectory('checkpoints').DIRECTORY_SEPARATOR.$id.'.json';
        $contentsHash = hash_file('sha256', $path);
        if (! is_string($contentsHash) || ! copy($path, $checkpointPath)) {
            throw new RuntimeException('The local connector could not create a pre-change checkpoint.');
        }
        if (! hash_equals($contentsHash, (string) hash_file('sha256', $checkpointPath))) {
            if (! unlink($checkpointPath)) {
                throw new RuntimeException('Checkpoint verification failed and the invalid copy could not be removed.');
            }
            throw new RuntimeException('The local connector checkpoint failed integrity verification.');
        }
        if (file_put_contents($metadataPath, json_encode([
            'path_sha256' => hash('sha256', $path),
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            if (! unlink($checkpointPath)) {
                throw new RuntimeException('Checkpoint metadata failed and the incomplete copy could not be removed.');
            }
            throw new RuntimeException('The local connector could not record checkpoint metadata.');
        }

        return $id;
    }

    private function assertRegularFile(string $path): void
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('The local connector supports regular files only; links and special files are denied.');
        }
        $size = filesize($path);
        if (! is_int($size) || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Files larger than 1 MiB are not available through the local connector.');
        }
    }

    private function unifiedDiff(string $previous, string $next): string
    {
        $oldLines = explode("\n", $previous);
        $newLines = explode("\n", $next);
        $prefix = 0;
        while (isset($oldLines[$prefix], $newLines[$prefix]) && $oldLines[$prefix] === $newLines[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while (
            $suffix < count($oldLines) - $prefix
            && $suffix < count($newLines) - $prefix
            && $oldLines[count($oldLines) - 1 - $suffix] === $newLines[count($newLines) - 1 - $suffix]
        ) {
            $suffix++;
        }
        $removed = array_slice($oldLines, $prefix, count($oldLines) - $prefix - $suffix);
        $added = array_slice($newLines, $prefix, count($newLines) - $prefix - $suffix);
        $lines = ['--- current', '+++ proposed'];
        foreach ($removed as $line) {
            $lines[] = '-'.$line;
        }
        foreach ($added as $line) {
            $lines[] = '+'.$line;
        }

        return implode(PHP_EOL, $lines);
    }
}
