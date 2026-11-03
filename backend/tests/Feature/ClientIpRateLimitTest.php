<?php

use Illuminate\Support\Facades\Route;

const CADDY_IP = '172.18.0.5';
const UNTRUSTED_IP = '203.0.113.50';

/**
 * Sends the login request (5 per minute) from a given connecting address.
 *
 * @param  array<string, string>  $headers
 */
function loginFrom(string $remoteAddress, array $headers = [], string $url = '/api/auth/login'): int
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders($headers)
        ->postJson($url, [])
        ->getStatusCode();
}

test('forged forwarding headers from an untrusted source do not get a fresh bucket', function (string $header, ?string $trustedProxy) {
    config(['trustedproxy.proxies' => $trustedProxy]);

    foreach (range(1, 5) as $attempt) {
        expect(loginFrom(UNTRUSTED_IP, [$header => "198.51.100.{$attempt}"]))->not->toBe(429);
    }

    expect(loginFrom(UNTRUSTED_IP, [$header => '198.51.100.99']))->toBe(429);
})->with([
    'X-Forwarded-For, Caddy configured' => ['X-Forwarded-For', CADDY_IP],
    'X-Forwarded-For, nothing configured' => ['X-Forwarded-For', null],
    'CF-Connecting-IP, Caddy configured' => ['CF-Connecting-IP', CADDY_IP],
    'CF-Connecting-IP, nothing configured' => ['CF-Connecting-IP', null],
]);

test('a forged Host cannot switch on the framework trust for Forge and Vapor', function () {
    config(['trustedproxy.proxies' => null]);
    $url = 'http://example.on-forge.com/api/auth/login';

    foreach (range(1, 5) as $attempt) {
        expect(loginFrom(UNTRUSTED_IP, ['X-Forwarded-For' => "198.51.100.{$attempt}"], $url))->not->toBe(429);
    }

    expect(loginFrom(UNTRUSTED_IP, ['X-Forwarded-For' => '198.51.100.99'], $url))->toBe(429);
});

test('CF-Connecting-IP is ignored on requests from Caddy, which alone decides whether to honour it', function () {
    config(['trustedproxy.proxies' => CADDY_IP]);

    foreach (range(1, 5) as $attempt) {
        expect(loginFrom(CADDY_IP, ['X-Forwarded-For' => '198.51.100.1', 'CF-Connecting-IP' => "198.51.100.{$attempt}"]))->not->toBe(429);
    }

    expect(loginFrom(CADDY_IP, ['X-Forwarded-For' => '198.51.100.1', 'CF-Connecting-IP' => '198.51.100.99']))->toBe(429);
});

test('two real clients behind the trusted proxy get separate buckets', function () {
    config(['trustedproxy.proxies' => CADDY_IP]);

    foreach (range(1, 5) as $attempt) {
        expect(loginFrom(CADDY_IP, ['X-Forwarded-For' => '198.51.100.1']))->not->toBe(429);
    }

    expect(loginFrom(CADDY_IP, ['X-Forwarded-For' => '198.51.100.1']))->toBe(429)
        ->and(loginFrom(CADDY_IP, ['X-Forwarded-For' => '198.51.100.2']))->not->toBe(429);
});

test('the invite preview limit is per client too', function () {
    config(['trustedproxy.proxies' => CADDY_IP]);

    $previewFrom = fn (string $client): int => $this
        ->withServerVariables(['REMOTE_ADDR' => CADDY_IP])
        ->withHeaders(['X-Forwarded-For' => $client])
        ->getJson('/api/invites/'.str_repeat('a', 32))
        ->getStatusCode();

    foreach (range(1, 10) as $attempt) {
        expect($previewFrom('198.51.100.1'))->not->toBe(429);
    }

    expect($previewFrom('198.51.100.1'))->toBe(429)
        ->and($previewFrom('198.51.100.2'))->not->toBe(429);
});

test('the client IP comes from X-Forwarded-For only when the connecting address is trusted', function (string $proxies, string $remoteAddress, string $expectedIp) {
    config(['trustedproxy.proxies' => $proxies]);
    Route::get('/_test/ip', fn () => response()->json(['ip' => request()->ip()]));

    $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.1'])
        ->getJson('/_test/ip')
        ->assertJsonPath('ip', $expectedIp);
})->with([
    'an IP matches' => [CADDY_IP, CADDY_IP, '198.51.100.1'],
    'a CIDR range matches' => ['172.18.0.0/16', CADDY_IP, '198.51.100.1'],
    'a hostname is resolved' => ['localhost', '127.0.0.1', '198.51.100.1'],
    'one of several matches' => ['192.0.2.1, '.CADDY_IP, CADDY_IP, '198.51.100.1'],
    'another address' => [CADDY_IP, UNTRUSTED_IP, UNTRUSTED_IP],
    'an unresolvable hostname trusts nobody' => ['avapeek-no-such-host.invalid', CADDY_IP, CADDY_IP],
    'a wildcard trusts nobody' => ['*', CADDY_IP, CADDY_IP],
    'the Laravel REMOTE_ADDR keyword trusts nobody' => ['REMOTE_ADDR', CADDY_IP, CADDY_IP],
]);

test('the trusted proxy can only set the client IP, not the scheme, host or port', function () {
    config(['trustedproxy.proxies' => CADDY_IP]);
    Route::get('/_test/origin', fn () => response()->json(['origin' => request()->getSchemeAndHttpHost()]));

    $this->withServerVariables(['REMOTE_ADDR' => CADDY_IP])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Port' => '8443',
        ])
        ->getJson('http://avapeek.test/_test/origin')
        ->assertJsonPath('origin', 'http://avapeek.test');
});
