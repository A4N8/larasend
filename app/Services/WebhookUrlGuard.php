<?php

namespace App\Services;

use App\Exceptions\UnsafeWebhookUrlException;
use App\Exceptions\WebhookDnsResolutionException;
use Symfony\Component\HttpFoundation\IpUtils;

class WebhookUrlGuard
{
    public function isSafe(string $url): bool
    {
        try {
            $this->requestOptions($url);

            return true;
        } catch (UnsafeWebhookUrlException|WebhookDnsResolutionException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function requestOptions(string $url): array
    {
        if (preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new UnsafeWebhookUrlException('The webhook URL is malformed.');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeWebhookUrlException('The webhook URL is malformed.');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new UnsafeWebhookUrlException('The webhook URL must use HTTP or HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrlException('Webhook URLs cannot contain credentials.');
        }

        $host = strtolower($parts['host']);
        $address = trim($host, '[]');
        $options = ['proxy' => ''];

        if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicIp($address)) {
                throw new UnsafeWebhookUrlException('The webhook URL cannot use a private or reserved IP address.');
            }

            return $options;
        }

        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! str_contains($host, '.') || str_ends_with($host, '.')
            || $host === 'localhost' || str_ends_with($host, '.localhost')
            || preg_match('/(?:^|\.)(?:0x[0-9a-f]+|[0-9]+)$/i', $host)) {
            throw new UnsafeWebhookUrlException('The webhook URL must use a public hostname.');
        }

        $addresses = $this->resolveHost($host);

        if ($addresses === []) {
            throw new WebhookDnsResolutionException('The webhook hostname could not be resolved.');
        }

        foreach ($addresses as $resolvedAddress) {
            if (! $this->isPublicIp($resolvedAddress)) {
                throw new UnsafeWebhookUrlException('The webhook hostname resolves to a private or reserved IP address.');
            }
        }

        $address = collect($addresses)->first(fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) ?? $addresses[0];
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $curlAddress = str_contains($address, ':') ? "[{$address}]" : $address;

        // Pin the validated answer while preserving the hostname for TLS and Host.
        $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$curlAddress}"]];

        return $options;
    }

    /** @return array<int, string> */
    protected function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter()->unique()->values()->all();
    }

    private function isPublicIp(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        return ! IpUtils::checkIp($address, [
            '224.0.0.0/4', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48',
            '2002::/16', '2001::/32', 'ff00::/8',
        ]);
    }
}
