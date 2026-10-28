<?php

namespace App\Llm;

use App\Models\User;
use InvalidArgumentException;

class CustomBaseUrlPolicy
{
    public function validate(string $url, bool $allowPrivateEndpoints = false): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            throw new InvalidArgumentException('A valid custom base URL is required.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $hasCredentials = isset($parts['user']) || isset($parts['pass']);
        $hasFragment = isset($parts['fragment']);
        $hasQuery = isset($parts['query']);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $hasCredentials || $hasFragment || $hasQuery) {
            throw new InvalidArgumentException('The custom base URL must be a valid HTTP(S) URL without credentials, query strings, or fragments.');
        }

        if (! $allowPrivateEndpoints && $scheme !== 'https') {
            throw new InvalidArgumentException('Custom base URLs must use HTTPS unless private endpoints are explicitly authorized.');
        }

        if ($allowPrivateEndpoints) {
            return rtrim($url, '/');
        }

        $this->assertPublicHost($host);

        return rtrim($url, '/');
    }

    public function userMayUsePrivateEndpoints(User $user): bool
    {
        return $user->can('llm.allow-private-endpoints');
    }

    private function assertPublicHost(string $host): void
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Custom base URLs cannot target localhost or local network hosts.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            throw new InvalidArgumentException('Custom base URL hostname must resolve to a public address.');
        }

        foreach ($addresses as $address) {
            $this->assertPublicIp($address);
        }
    }

    private function assertPublicIp(string $address): void
    {
        $publicAddress = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($publicAddress === false) {
            throw new InvalidArgumentException('Custom base URLs cannot resolve to private or reserved IP addresses.');
        }
    }
}
