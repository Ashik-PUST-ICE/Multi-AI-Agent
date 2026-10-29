<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserWebAccessSetting;
use RuntimeException;

class WebUrlPolicy
{
    /**
     * @return list<string>
     */
    public function resolvePublicHttpsUrl(User $user, string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new RuntimeException('Only HTTPS URLs on the standard port without embedded credentials are allowed.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (! $this->isValidPublicDomain($host)) {
            throw new RuntimeException('The URL host is not a public domain.');
        }

        $settings = UserWebAccessSetting::query()->where('user_id', $user->id)->first();
        $allowed = $settings?->allowed_domains ?? [];
        $denied = $settings?->denied_domains ?? [];
        if (! $this->domainMatchesAny($host, $allowed)) {
            throw new RuntimeException('The URL domain is not in your configured allowlist.');
        }
        if ($this->domainMatchesAny($host, $denied)) {
            throw new RuntimeException('The URL domain is on your configured denylist.');
        }

        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Secure pinned HTTPS requests require the PHP cURL extension.');
        }

        $addresses = $this->publicDnsAddresses($host);
        if ($addresses === []) {
            throw new RuntimeException('The URL host did not resolve to a public IP address.');
        }

        return array_map(
            static fn (string $address): string => $host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address),
            $addresses,
        );
    }

    /**
     * @param  list<string>  $domains
     */
    private function domainMatchesAny(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = strtolower(rtrim($domain, '.'));
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function isValidPublicDomain(string $host): bool
    {
        if (strlen($host) > 253
            || ! str_contains($host, '.')
            || str_contains($host, '%')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === ''
                || strlen($label) > 63
                || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function publicDnsAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address)) {
                continue;
            }

            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($address, FILTER_VALIDATE_IP, $flags) === false) {
                throw new RuntimeException('The URL host resolves to a private or reserved IP address.');
            }
            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }
}
