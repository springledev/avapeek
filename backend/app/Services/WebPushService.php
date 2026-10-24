<?php

namespace App\Services;

use App\Models\PushSubscription;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Throwable;

/**
 * Sends one generic Web Push message per subscription.
 *
 * The payload is only {type, url}: no names, ids, counts or content. The
 * library encrypts it per RFC 8291; this class never logs endpoints or keys.
 */
class WebPushService
{
    public const URL = '/recents';

    /** @var Closure(string, list<string>): ClientInterface */
    private Closure $clientFactory;

    /**
     * @param  (Closure(string, list<string>): ClientInterface)|null  $clientFactory  Builds the HTTP client for a host pinned to the given addresses.
     */
    public function __construct(private PushEndpointGuard $guard, ?Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? self::pinnedClient(...);
    }

    public function isEnabled(): bool
    {
        return (bool) config('push.enabled');
    }

    /**
     * @return array{type: string, url: string}
     */
    public static function payload(string $type): array
    {
        return ['type' => $type, 'url' => self::URL];
    }

    /**
     * Returns true when the push service accepted the message.
     */
    public function send(PushSubscription $subscription, string $type): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $check = $this->guard->check($subscription->endpoint);

        if ($check['reason'] === PushEndpointGuard::UNSAFE) {
            $this->log('push endpoint rejected by SSRF guard, subscription removed', $subscription);
            $subscription->delete();

            return false;
        }

        if (! $check['safe']) {
            $this->log('push endpoint could not be resolved, skipped', $subscription);

            return false;
        }

        try {
            $client = ($this->clientFactory)((string) $check['host'], $check['addresses']);
        } catch (Throwable) {
            $this->log('push DNS pin unavailable, send skipped', $subscription);

            return false;
        }

        try {
            $webPush = new WebPush(
                ['VAPID' => [
                    'subject' => (string) config('push.subject'),
                    'publicKey' => (string) config('push.public_key'),
                    'privateKey' => (string) config('push.private_key'),
                ]],
                ['TTL' => (int) config('push.ttl_seconds'), 'urgency' => 'low'],
                $client,
            );

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth,
                    'contentEncoding' => 'aes128gcm',
                ]),
                json_encode(self::payload($type), JSON_THROW_ON_ERROR),
            );
        } catch (Throwable $e) {
            $this->log('push send threw '.$e::class, $subscription);
            $this->recordFailure($subscription);

            return false;
        }

        if ($report->isSuccess()) {
            $subscription->forceFill(['failed_attempts' => 0, 'last_success_at' => now()])->save();

            return true;
        }

        if ($report->isSubscriptionExpired()) {
            $subscription->delete();

            return false;
        }

        $this->log('push service rejected message', $subscription);
        $this->recordFailure($subscription);

        return false;
    }

    private function recordFailure(PushSubscription $subscription): void
    {
        $attempts = $subscription->failed_attempts + 1;

        if ($attempts >= (int) config('push.max_failed_attempts')) {
            $subscription->delete();

            return;
        }

        $subscription->forceFill(['failed_attempts' => $attempts])->save();
    }

    private function log(string $message, PushSubscription $subscription): void
    {
        Log::warning($message, ['endpoint_hash' => $subscription->endpoint_hash]);
    }

    /**
     * Guzzle client that never follows redirects, times out after 10 s and
     * pins the hostname to the addresses the guard just validated (so a DNS
     * change between check and connect cannot reach an internal host).
     *
     * @param  list<string>  $addresses
     */
    public static function pinnedClient(string $host, array $addresses): ClientInterface
    {
        $timeout = (float) config('push.http_timeout_seconds');
        if (! function_exists('curl_version') || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('cURL is required to pin push endpoint DNS.');
        }

        $options = [
            'handler' => HandlerStack::create(new CurlHandler),
            // An empty string (not null) makes Guzzle set CURLOPT_PROXY='' so libcurl ignores
            // HTTPS_PROXY/ALL_PROXY/NO_PROXY and the DNS pin cannot be bypassed via a proxy.
            'proxy' => '',
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        $options['curl'] = [
            CURLOPT_RESOLVE => ["{$host}:443:".implode(',', array_map(
                fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip,
                $addresses,
            ))],
        ];

        return new Client($options);
    }
}
