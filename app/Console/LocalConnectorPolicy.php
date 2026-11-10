<?php

namespace App\Console;

use RuntimeException;

final class LocalConnectorPolicy
{
    private const CAPABILITIES = [
        'file_read',
        'file_write',
        'terminal',
        'browser',
    ];

    private const DENIED_PATH_SEGMENTS = [
        '.ssh',
        '.gnupg',
        '.aws',
        '.azure',
        '.config/google-chrome',
        '.config/chromium',
        'google/chrome/user data',
        'mozilla/firefox/profiles',
        'login data',
        'cookies',
        'credentials',
        '.env',
        '.env.*',
        'id_rsa',
        'id_ed25519',
        'known_hosts',
        'authorized_keys',
        'password',
        'passwords',
        'secrets',
    ];

    /**
     * @return array{capabilities: array<string, bool>, allowed_folders: list<string>, trust_level: int, access_mode: string, never_auto: list<string>, updated_at: ?string}
     */
    public function policy(): array
    {
        $policy = $this->defaultPolicy();
        $file = $this->policyFile();
        if (! is_file($file)) {
            return $policy;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Could not read the local connector policy.');
        }
        try {
            $stored = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('The local connector policy is invalid. Disable the connector and repair its configuration.', 0, $exception);
        }
        if (! is_array($stored)) {
            throw new RuntimeException('The local connector policy must be a JSON object.');
        }

        foreach (self::CAPABILITIES as $capability) {
            if (isset($stored['capabilities'][$capability]) && is_bool($stored['capabilities'][$capability])) {
                $policy['capabilities'][$capability] = $stored['capabilities'][$capability];
            }
        }
        if (is_int($stored['trust_level'] ?? null) && $stored['trust_level'] >= 0 && $stored['trust_level'] <= 4) {
            $policy['trust_level'] = $stored['trust_level'];
        }
        if (array_key_exists('access_mode', $stored) && $stored['access_mode'] !== 'user') {
            throw new RuntimeException('The local connector policy cannot enable Auto access mode.');
        }
        if (($stored['access_mode'] ?? null) === 'user') {
            $policy['access_mode'] = 'user';
        }
        foreach (($stored['allowed_folders'] ?? []) as $folder) {
            if (! is_string($folder) || ($resolved = realpath($folder)) === false || ! is_dir($resolved)) {
                throw new RuntimeException('A configured connector folder is missing or is not a directory.');
            }
            $policy['allowed_folders'][] = $this->normalizePath($resolved);
        }
        $policy['allowed_folders'] = array_values(array_unique($policy['allowed_folders']));
        $policy['updated_at'] = is_string($stored['updated_at'] ?? null) ? $stored['updated_at'] : null;

        return $policy;
    }

    public function enableCapability(string $capability): void
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw new RuntimeException('Unknown local connector capability.');
        }
        if (in_array($capability, ['terminal', 'browser'], true)) {
            throw new RuntimeException('Terminal and browser capabilities are disabled until they can use the required isolated local runtimes.');
        }
        $policy = $this->policy();
        $policy['capabilities'][$capability] = true;
        $this->save($policy);
    }

    public function disableCapability(string $capability): void
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw new RuntimeException('Unknown local connector capability.');
        }
        $policy = $this->policy();
        $policy['capabilities'][$capability] = false;
        $this->save($policy);
    }

    public function addAllowedFolder(string $folder): string
    {
        $resolved = realpath($folder);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('Allowlisted connector folders must already exist and be directories.');
        }
        $normalized = $this->normalizePath($resolved);
        $policy = $this->policy();
        if (! in_array($normalized, $policy['allowed_folders'], true)) {
            $policy['allowed_folders'][] = $normalized;
        }
        $this->save($policy);

        return $normalized;
    }

    public function removeAllowedFolder(string $folder): void
    {
        $resolved = realpath($folder);
        $normalized = $this->normalizePath($resolved === false ? $folder : $resolved);
        $policy = $this->policy();
        $policy['allowed_folders'] = array_values(array_filter(
            $policy['allowed_folders'],
            fn (string $allowed): bool => $allowed !== $normalized,
        ));
        $this->save($policy);
    }

    public function setTrustLevel(int $trustLevel): void
    {
        if ($trustLevel < 0 || $trustLevel > 4) {
            throw new RuntimeException('Local connector trust level must be between 0 and 4.');
        }
        $policy = $this->policy();
        $policy['trust_level'] = $trustLevel;
        $this->save($policy);
    }

    public function setAccessMode(string $accessMode): void
    {
        if ($accessMode !== 'user') {
            throw new RuntimeException('Local connector Auto access is disabled; local actions require the user mode.');
        }
        $policy = $this->policy();
        $policy['access_mode'] = 'user';
        $this->save($policy);
    }

    public function assertCapabilityEnabled(string $capability): void
    {
        if (! ($this->policy()['capabilities'][$capability] ?? false)) {
            throw new RuntimeException("Local connector capability [{$capability}] is disabled.");
        }
        $policy = $this->policy();
        $minimumTrust = $capability === 'file_read' ? 1 : 2;
        if ($policy['trust_level'] < $minimumTrust) {
            throw new RuntimeException("Local connector capability [{$capability}] requires local Trust Level {$minimumTrust}.");
        }
        if ($policy['access_mode'] !== 'user') {
            throw new RuntimeException('Local connector actions require user-controlled access mode.');
        }
        if (is_file($this->killSwitchFile())) {
            throw new RuntimeException('The local connector kill switch is active.');
        }
    }

    public function assertAllowedPath(string $path, bool $mustExist = true): string
    {
        if (str_contains($path, "\0") || preg_match('/^(?:\\\\\\\\\?\\\\|\\\\\\\\\.\\\\)/', $path) === 1) {
            throw new RuntimeException('The requested path is invalid.');
        }
        if ($this->isDenied($this->normalizePath($path))) {
            throw new RuntimeException('This path is on the local connector deny-list.');
        }
        $resolved = $mustExist ? realpath($path) : $this->resolveForCreate($path);
        if ($resolved === null) {
            throw new RuntimeException('The requested path could not be resolved.');
        }
        $normalized = $this->normalizePath($resolved);
        if ($this->isDenied($normalized)) {
            throw new RuntimeException('This path is on the local connector deny-list.');
        }
        foreach ($this->policy()['allowed_folders'] as $allowed) {
            if ($normalized === $allowed || str_starts_with($normalized, rtrim($allowed, '/').'/')) {
                return $resolved;
            }
        }

        throw new RuntimeException('This path is outside the configured local connector folder allow-list.');
    }

    public function assertLiveMode(bool $live): void
    {
        if (! $live) {
            return;
        }
        if (is_file($this->killSwitchFile())) {
            throw new RuntimeException('The local connector kill switch is active.');
        }
    }

    public function activateKillSwitch(): void
    {
        $this->ensureDirectory($this->connectorDirectory());
        $file = $this->killSwitchFile();
        if (file_put_contents($file, now()->toIso8601String(), LOCK_EX) === false) {
            throw new RuntimeException('Could not activate the local connector kill switch.');
        }
    }

    public function deactivateKillSwitch(): void
    {
        $file = $this->killSwitchFile();
        if (is_file($file) && ! unlink($file)) {
            throw new RuntimeException('Could not deactivate the local connector kill switch.');
        }
    }

    public function log(string $operation, string $path, string $mode, string $result): void
    {
        $directory = $this->connectorDirectory();
        $this->ensureDirectory($directory);
        $line = json_encode([
            'occurred_at' => now()->toIso8601String(),
            'operation' => $operation,
            'path_sha256' => hash('sha256', $path),
            'mode' => $mode,
            'result' => $result,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($directory.DIRECTORY_SEPARATOR.'activity.jsonl', $line.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('The local connector activity could not be logged.');
        }
    }

    public function storageDirectory(string $name): string
    {
        if (! in_array($name, ['checkpoints', 'trash'], true)) {
            throw new RuntimeException('Unknown connector storage area.');
        }
        $directory = $this->connectorDirectory().DIRECTORY_SEPARATOR.$name;
        $this->ensureDirectory($directory);

        return $directory;
    }

    public function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', rtrim($path, '\\/'));

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private function isDenied(string $normalizedPath): bool
    {
        $segments = explode('/', strtolower($normalizedPath));
        foreach ($segments as $segment) {
            if ($segment === '.env' || str_starts_with($segment, '.env.')) {
                return true;
            }
            if (preg_match('/^(?:passwords?|credentials?|secrets?)(?:[._ -].*)?$/', $segment) === 1
                || str_starts_with($segment, 'id_rsa')
                || str_starts_with($segment, 'id_ed25519')) {
                return true;
            }
            foreach (self::DENIED_PATH_SEGMENTS as $denied) {
                if ($segment === $denied || str_ends_with($normalizedPath, '/'.$denied)) {
                    return true;
                }
            }
        }

        foreach (['/.ssh/', '/.gnupg/', '/google/chrome/user data/', '/mozilla/firefox/profiles/'] as $deniedPath) {
            if (str_contains($normalizedPath.'/', $deniedPath)) {
                return true;
            }
        }

        return false;
    }

    private function resolveForCreate(string $path): ?string
    {
        $parent = dirname($path);
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false) {
            return null;
        }
        $basename = basename($path);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return null;
        }

        return $resolvedParent.DIRECTORY_SEPARATOR.$basename;
    }

    /**
     * @return array{capabilities: array<string, bool>, allowed_folders: list<string>, trust_level: int, access_mode: string, never_auto: list<string>, updated_at: ?string}
     */
    private function defaultPolicy(): array
    {
        return [
            'capabilities' => [
                'file_read' => false,
                'file_write' => false,
                'terminal' => false,
                'browser' => false,
            ],
            'allowed_folders' => [],
            'trust_level' => 0,
            'access_mode' => 'user',
            'never_auto' => ['file_write', 'file_trash', 'file_restore', 'terminal', 'browser'],
            'updated_at' => null,
        ];
    }

    private function save(array $policy): void
    {
        $policy['updated_at'] = now()->toIso8601String();
        $directory = $this->connectorDirectory();
        $this->ensureDirectory($directory);
        $file = $this->policyFile();
        $temporary = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($policy, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the local connector policy.');
        }
        if (! rename($temporary, $file)) {
            if (! unlink($temporary)) {
                throw new RuntimeException('Could not activate connector policy or remove its temporary file.');
            }
            throw new RuntimeException('Could not activate the local connector policy.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($file, 0600)) {
            throw new RuntimeException('Could not restrict permissions on the connector policy file.');
        }
    }

    private function connectorDirectory(): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = getenv('APPDATA') ?: getenv('USERPROFILE');
            if (! is_string($root) || $root === '') {
                throw new RuntimeException('The current Windows user profile directory is unavailable.');
            }

            return $root.DIRECTORY_SEPARATOR.'Multi-AI-Agent'.DIRECTORY_SEPARATOR.'connector';
        }

        $root = getenv('XDG_CONFIG_HOME') ?: getenv('HOME');
        if (! is_string($root) || $root === '') {
            throw new RuntimeException('The current user home directory is unavailable.');
        }
        $base = getenv('XDG_CONFIG_HOME')
            ? $root
            : $root.DIRECTORY_SEPARATOR.'.config';

        return $base.DIRECTORY_SEPARATOR.'multi-ai-agent'.DIRECTORY_SEPARATOR.'connector';
    }

    private function policyFile(): string
    {
        return $this->connectorDirectory().DIRECTORY_SEPARATOR.'policy.json';
    }

    private function killSwitchFile(): string
    {
        return $this->connectorDirectory().DIRECTORY_SEPARATOR.'KILL_SWITCH';
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the local connector configuration directory.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($directory, 0700)) {
            throw new RuntimeException('Could not restrict permissions on the local connector directory.');
        }
    }
}
