<?php

namespace App\Services;

use Closure;

/**
 * SSRF guard for client-supplied Web Push endpoints.
 *
 * The server POSTs to these URLs, so they must be https, on the default port,
 * must not be (or resolve to) loopback, private, link-local or reserved
 * addresses. There is deliberately no host allow-list.
 */
class PushEndpointGuard
{
    public const MAX_LENGTH = 2048;

    public const UNSAFE = 'unsafe';

    public const UNRESOLVED = 'unresolved';

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver  Returns every IPv4/IPv6 address of a hostname.
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolveWithDns(...);
    }

    /**
     * @return array{safe: bool, host: ?string, addresses: list<string>, reason: ?string}
     */
    public function check(string $endpoint): array
    {
        $host = $this->syntacticHost($endpoint);

        if ($host === null) {
            return $this->result(false, null, [], self::UNSAFE);
        }

        $addresses = array_values(array_unique(($this->resolver)($host)));

        if ($addresses === []) {
            return $this->result(false, $host, [], self::UNRESOLVED);
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                return $this->result(false, $host, $addresses, self::UNSAFE);
            }
        }

        return $this->result(true, $host, $addresses, null);
    }

    public function isSafe(string $endpoint): bool
    {
        return $this->check($endpoint)['safe'];
    }

    /**
     * Validates scheme, length, credentials, port and host shape without any
     * network access. Returns the lowercase hostname, or null if rejected.
     */
    public function syntacticHost(string $endpoint): ?string
    {
        if ($endpoint === '' || strlen($endpoint) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint)) {
            return null;
        }

        $parts = parse_url($endpoint);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
            return null;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return null;
        }

        $host = strtolower($parts['host']);

        // A trailing dot is never normalised: curl would request "host." and miss the DNS pin for "host".
        if ($host === '' || str_ends_with($host, '.') || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        // Any IP literal (including bracketed IPv6) is refused; push services use hostnames.
        if (str_contains($host, ':') || str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // Numeric-looking hosts such as 2130706433 or 0x7f.1 are alternate IPv4 spellings.
        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)(\.(0x[0-9a-f]*|[0-9]+))*$/i', $host)) {
            return null;
        }

        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) addresses inherit the IPv4 verdict.
            if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
                return self::isPublicAddress((string) inet_ntop(substr($packed, 12)));
            }

            // NAT64 well-known prefix 64:ff9b::/96 embeds an IPv4 address.
            if (str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
                return self::isPublicAddress((string) inet_ntop(substr($packed, 12)));
            }

            // Unique local fc00::/7, link-local fe80::/10 and site-local fec0::/10.
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            if (($first & 0xFE) === 0xFC || ($first === 0xFE && ($second & 0xC0) !== 0)) {
                return false;
            }

            // Documentation 2001:db8::/32 and 6to4 2002::/16 are not valid push hosts.
            if (str_starts_with($packed, "\x20\x01\x0d\xb8") || str_starts_with($packed, "\x20\x02")) {
                return false;
            }
        } else {
            $long = ip2long($address);
            // Shared address space (carrier-grade NAT) 100.64.0.0/10.
            if ($long !== false && ($long & 0xFFC00000) === 0x64400000) {
                return false;
            }
            // IETF protocol assignments 192.0.0.0/24 and benchmarking 198.18.0.0/15.
            if ($long !== false && (($long & 0xFFFFFF00) === 0xC0000000 || ($long & 0xFFFE0000) === 0xC6120000)) {
                return false;
            }
            // Multicast and everything above (224.0.0.0/3).
            if ($long !== false && ($long & 0xE0000000) === 0xE0000000) {
                return false;
            }
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @return list<string>
     */
    public static function resolveWithDns(string $host): array
    {
        $addresses = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = $ipv4;
        }

        $ipv6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6)) {
            foreach ($ipv6 as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values($addresses);
    }

    /**
     * @param  list<string>  $addresses
     * @return array{safe: bool, host: ?string, addresses: list<string>, reason: ?string}
     */
    private function result(bool $safe, ?string $host, array $addresses, ?string $reason): array
    {
        return ['safe' => $safe, 'host' => $host, 'addresses' => $addresses, 'reason' => $reason];
    }
}
