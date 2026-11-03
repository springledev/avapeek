<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Trusts X-Forwarded-For only when the request comes from the bundled Caddy.
 *
 * Caddy overwrites that header with the client address it resolved itself, so a
 * single trusted hop is all the backend ever needs. Anything upstream of Caddy is
 * Caddy's decision (see docker/caddy/Caddyfile).
 *
 * Only X-Forwarded-For is honoured: the scheme, host and port the application sees
 * stay what they were, since URLs are built from APP_URL and FRONTEND_URL.
 */
class TrustCaddyProxy extends TrustProxies
{
    /**
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR;

    /**
     * Replaces the parent logic, which trusts every source when no proxy is
     * configured and the client-supplied Host ends in on-forge.com or on-vapor.com.
     */
    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        $this->setTrustedProxyIpAddressesToSpecificIps($request, $this->trustedProxyAddresses());
    }

    /**
     * Resolves the configured proxies to addresses on every request, because a
     * Docker service gets a new IP when its container is recreated. A name that
     * does not resolve is dropped, so a failed lookup trusts nobody.
     *
     * @return list<string>
     */
    private function trustedProxyAddresses(): array
    {
        $proxies = array_filter(array_map(trim(...), explode(',', (string) config('trustedproxy.proxies'))));

        $addresses = [];

        foreach ($proxies as $proxy) {
            $isAddress = str_contains($proxy, '/') || filter_var($proxy, FILTER_VALIDATE_IP) !== false;

            array_push($addresses, ...($isAddress ? [$proxy] : (gethostbynamel($proxy) ?: [])));
        }

        return $addresses;
    }
}
