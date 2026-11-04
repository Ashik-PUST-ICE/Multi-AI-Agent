<?php

namespace App\Services;

use App\Models\User;
use InvalidArgumentException;
use LogicException;

class SafeSandboxService
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runCode(User $user, string $command, array $options = []): array
    {
        $this->assertEnabled('sandbox.code_execution.enabled');
        $normalized = $this->normalizeCommand($command);

        return [
            'status' => 'accepted',
            'user_id' => $user->id,
            'command' => $normalized,
            'timeout_seconds' => (int) ($options['timeout_seconds'] ?? 30),
            'workdir' => isset($options['workdir']) && is_string($options['workdir']) ? trim($options['workdir']) : null,
            'sandbox' => 'docker-isolated',
            'network' => 'disabled',
            'filesystem' => 'ephemeral-copy',
            'notice' => 'This sandbox is disabled by default and is only available after explicit owner approval and isolated backend validation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runBrowser(User $user, string $url, array $options = []): array
    {
        $this->assertEnabled('sandbox.browser.enabled');

        $normalizedUrl = trim($url);
        if ($normalizedUrl === '') {
            throw new InvalidArgumentException('A non-empty URL is required.');
        }

        return [
            'status' => 'accepted',
            'user_id' => $user->id,
            'url' => $normalizedUrl,
            'action' => (string) ($options['action'] ?? 'read'),
            'sandbox' => 'docker-browser',
            'network' => 'allowlisted-only',
            'cookies' => 'isolated',
            'notice' => 'Browser actions are disabled by default and require explicit owner approval in an isolated container.',
        ];
    }

    public function assertEnabled(string $configKey): void
    {
        if (! (bool) config($configKey, false)) {
            throw new LogicException('This action remains disabled by default. Enable the isolated sandbox only after explicit owner approval.');
        }
    }

    public function normalizeCommand(string $command): string
    {
        $normalized = trim($command);
        if ($normalized === '') {
            throw new InvalidArgumentException('A non-empty command is required.');
        }

        $forbidden = [
            'rm -rf',
            'sudo ',
            'chmod 777',
            'wget http',
            'curl http',
            'docker run',
            '--privileged',
            '/etc/passwd',
            '/var/run/docker.sock',
        ];

        foreach ($forbidden as $pattern) {
            if (stripos($normalized, $pattern) !== false) {
                throw new InvalidArgumentException('Dangerous command patterns are blocked in the sandbox.');
            }
        }

        return $normalized;
    }
}
