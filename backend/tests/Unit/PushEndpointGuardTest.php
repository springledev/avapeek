<?php

use App\Services\PushEndpointGuard;

it('classifies addresses', function (string $address, bool $public) {
    expect(PushEndpointGuard::isPublicAddress($address))->toBe($public);
})->with([
    ['93.184.216.34', true],
    ['2606:2800:220:1:248:1893:25c8:1946', true],
    ['127.0.0.1', false],
    ['10.1.2.3', false],
    ['172.16.0.1', false],
    ['192.168.1.1', false],
    ['169.254.169.254', false],
    ['192.0.0.8', false],
    ['198.18.0.1', false],
    ['198.19.255.255', false],
    ['198.20.0.1', true],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['224.0.0.1', false],
    ['::1', false],
    ['::', false],
    ['fc00::1', false],
    ['fd12:3456::1', false],
    ['fe80::1', false],
    ['::ffff:192.168.0.1', false],
    ['::ffff:8.8.8.8', true],
    ['64:ff9b::7f00:1', false],
    ['not-an-ip', false],
]);

it('rejects bad hosts without any DNS lookup', function (string $url) {
    $guard = new PushEndpointGuard(fn () => throw new RuntimeException('resolver must not be called'));

    expect($guard->syntacticHost($url))->toBeNull();
})->with([
    'http://example.com/x',
    'ftp://example.com/x',
    'https://localhost/x',
    'https://LOCALHOST./x',
    'https://host.example.com./x',
    'https://host.example.com.:443/x',
    'https://[::1]/x',
    'https://1.2.3.4/x',
    'https://0x7f.1/x',
    'https://example.com:444/x',
    'https://u:p@example.com/x',
    'https:///x',
    '',
]);

it('accepts a plain public https host and honours the resolver', function () {
    $guard = new PushEndpointGuard(fn () => ['93.184.216.34']);

    expect($guard->check('https://updates.push.services.mozilla.com/wpush/v2/abc'))
        ->toMatchArray(['safe' => true, 'host' => 'updates.push.services.mozilla.com', 'reason' => null]);
});

it('returns the exact host used in the request URL so the DNS pin key matches', function (string $url) {
    $guard = new PushEndpointGuard(fn () => ['93.184.216.34']);

    expect($guard->check($url)['host'])->toBe(strtolower(parse_url($url, PHP_URL_HOST)));
})->with([
    'https://good.example.com/x',
    'https://Good.Example.COM/x',
    'https://good.example.com:443/x',
]);

it('reports unsafe versus unresolved', function () {
    expect((new PushEndpointGuard(fn () => ['10.0.0.1']))->check('https://a.example.test/x')['reason'])->toBe('unsafe')
        ->and((new PushEndpointGuard(fn () => []))->check('https://a.example.test/x')['reason'])->toBe('unresolved');
});
