<?php

namespace App\Console;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

final class CliApiClient
{
    private ?array $credentials = null;

    public function request(
        string $method,
        string $path,
        array $data = [],
        ?string $apiUrl = null,
        bool $authenticated = true,
    ): array {
        $baseUrl = $this->apiBaseUrl($apiUrl);
        $pending = Http::acceptJson()->timeout(30);
        if ($authenticated) {
            $credentials = $this->credentials();
            if ($apiUrl !== null && $this->normalizeBaseUrl($apiUrl) !== $credentials['api_url']) {
                throw new RuntimeException('The API URL differs from the signed-in endpoint. Run agent:login for that server.');
            }
            $pending = $pending->withToken($credentials['token']);
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($baseUrl.$path, $data),
                'POST' => $pending->post($baseUrl.$path, $data),
                default => throw new RuntimeException('Unsupported CLI API method.'),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to the configured API server.', 0, $exception);
        }

        return $this->decodeResponse($response);
    }

    public function requestDeviceCode(string $apiUrl, string $deviceName): array
    {
        return $this->request(
            'POST',
            '/auth/device/code',
            ['device_name' => $deviceName],
            $apiUrl,
            false,
        );
    }

    public function pollDeviceToken(string $apiUrl, string $deviceCode): array
    {
        $baseUrl = $this->apiBaseUrl($apiUrl);
        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->post($baseUrl.'/auth/device/token', ['device_code' => $deviceCode]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not connect to the configured API server.', 0, $exception);
        }

        return $this->decodeResponse($response);
    }

    public function saveCredentials(string $apiUrl, string $token): void
    {
        $directory = $this->credentialsDirectory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the private CLI credential directory.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($directory, 0700)) {
            throw new RuntimeException('Could not restrict permissions on the CLI credential directory.');
        }
        $file = $directory.DIRECTORY_SEPARATOR.'credentials.json';
        $json = json_encode([
            'api_url' => $this->normalizeBaseUrl($apiUrl),
            'token' => $token,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not save CLI credentials.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($file, 0600)) {
            if (! unlink($file)) {
                throw new RuntimeException('Could not secure the CLI credential file or remove the insecure file.');
            }
            throw new RuntimeException('Could not restrict permissions on the CLI credential file.');
        }
        $this->credentials = null;
    }

    public function clearCredentials(): void
    {
        $file = $this->credentialsDirectory().DIRECTORY_SEPARATOR.'credentials.json';
        if (is_file($file) && ! unlink($file)) {
            throw new RuntimeException('Could not remove the local CLI credentials.');
        }
        $this->credentials = null;
    }

    public function streamTask(int $taskId, OutputInterface $output, bool $json = false): array
    {
        $credentials = $this->credentials();
        $cursor = 0;
        $deadline = time() + 3600;

        do {
            $baseUrl = $this->apiBaseUrl($credentials['api_url']);
            try {
                $response = Http::withToken($credentials['token'])
                    ->accept('text/event-stream')
                    ->timeout(30)
                    ->withOptions(['stream' => true])
                    ->get($baseUrl."/tasks/{$taskId}/events/stream", ['after' => $cursor]);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Task progress stream disconnected. Retry with agent:run or agent:status.', 0, $exception);
            }

            if (! $response->successful()) {
                $this->decodeResponse($response);
            }

            $body = $response->toPsrResponse()->getBody();
            $buffer = '';
            while (! $body->eof()) {
                $buffer .= $body->read(4096);
                while (($frameEnd = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $frameEnd);
                    $buffer = substr($buffer, $frameEnd + 2);
                    $event = $this->parseSseFrame($frame);
                    if ($event === null) {
                        continue;
                    }
                    $cursor = max($cursor, $event['id']);
                    if (! $json) {
                        $output->writeln(sprintf(
                            '<info>[%s]</info> %s',
                            $event['data']['status'] ?? 'event',
                            $event['data']['event_type'] ?? 'task.progress',
                        ));
                    }
                }
            }

            $taskData = $this->request('GET', "/tasks/{$taskId}")['task'] ?? null;
            if (! is_array($taskData)) {
                throw new RuntimeException('The task status response was incomplete.');
            }
            if (in_array((int) ($taskData['status'] ?? -1), [2, 3, 4], true)) {
                return $taskData;
            }
            if (time() >= $deadline) {
                throw new RuntimeException('Stopped waiting after one hour. Check the task later with agent:status.');
            }
        } while (true);
    }

    public function apiBaseUrl(?string $apiUrl = null): string
    {
        $url = $apiUrl ?? $this->credentials()['api_url'] ?? (string) config('app.url');

        return $this->normalizeBaseUrl($url).'/api/v1';
    }

    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }
        $file = $this->credentialsDirectory().DIRECTORY_SEPARATOR.'credentials.json';
        if (! is_file($file)) {
            throw new RuntimeException('Not signed in. Run agent:login first.');
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Could not read the CLI credential file.');
        }

        try {
            $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The CLI credential file is invalid. Run agent:logout and agent:login again.', 0, $exception);
        }
        if (
            ! is_array($credentials)
            || ! is_string($credentials['token'] ?? null)
            || $credentials['token'] === ''
            || ! is_string($credentials['api_url'] ?? null)
        ) {
            throw new RuntimeException('The CLI credential file is invalid. Run agent:logout and agent:login again.');
        }
        $this->credentials = [
            'api_url' => $this->normalizeBaseUrl($credentials['api_url']),
            'token' => $credentials['token'],
        ];

        return $this->credentials;
    }

    private function credentialsDirectory(): string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = getenv('APPDATA') ?: getenv('USERPROFILE');
            if (! is_string($root) || $root === '') {
                throw new RuntimeException('The current Windows user profile directory is unavailable.');
            }

            return $root.DIRECTORY_SEPARATOR.'Multi-AI-Agent';
        }

        $root = getenv('XDG_CONFIG_HOME') ?: getenv('HOME');
        if (! is_string($root) || $root === '') {
            throw new RuntimeException('The current user home directory is unavailable.');
        }

        return getenv('XDG_CONFIG_HOME')
            ? $root.DIRECTORY_SEPARATOR.'multi-ai-agent'
            : $root.DIRECTORY_SEPARATOR.'.config'.DIRECTORY_SEPARATOR.'multi-ai-agent';
    }

    private function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (
            ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('The API URL must be absolute and must not contain credentials, query strings, or fragments.');
        }
        $host = strtolower($parts['host']);
        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
        if ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && $localHost)) {
            throw new RuntimeException('The CLI requires HTTPS for remote APIs; HTTP is allowed only for localhost and reserved .test domains.');
        }

        return $url;
    }

    private function decodeResponse(Response $response): array
    {
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The API returned an invalid response.', 0, $exception);
        }
        if (! is_array($data)) {
            throw new RuntimeException('The API returned an invalid response.');
        }
        if (! $response->successful()) {
            $message = is_string($data['message'] ?? null)
                ? $data['message']
                : 'The API request failed with HTTP '.$response->status().'.';
            throw new CliApiException($message, $response->status());
        }

        return $data;
    }

    /**
     * @return array{id: int, data: array<string, mixed>}|null
     */
    private function parseSseFrame(string $frame): ?array
    {
        $id = null;
        $dataLines = [];
        foreach (explode("\n", str_replace("\r", '', $frame)) as $line) {
            if (str_starts_with($line, 'id:')) {
                $candidate = trim(substr($line, 3));
                if (ctype_digit($candidate)) {
                    $id = (int) $candidate;
                }
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if ($id === null || $dataLines === []) {
            return null;
        }
        try {
            $data = json_decode(implode("\n", $dataLines), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The task progress stream contained invalid JSON.', 0, $exception);
        }

        if (! is_array($data)) {
            throw new RuntimeException('The task progress stream contained invalid data.');
        }

        return ['id' => $id, 'data' => $data];
    }
}
