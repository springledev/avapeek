<?php

use App\Services\StorageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fake the s3 disk and make its presigned URLs point at a fake host whose path is the object key.
 */
function stubS3Disk(): void
{
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path) => 'https://bucket.example-storage.test/'.$path.'?X-Amz-Signature=abc'
    );
}

function fakeStorageHttp(array $headers): void
{
    Http::fake(function ($request) use ($headers) {
        $path = ltrim(parse_url($request->url(), PHP_URL_PATH), '/');

        return Http::response(Storage::disk('s3')->get($path), 200, $headers);
    });
}

function configureS3(array $overrides): void
{
    config(['filesystems.disks.s3' => array_merge(config('filesystems.disks.s3'), [
        'key' => 'k', 'secret' => 's', 'bucket' => 'photos',
    ], $overrides)]);
    Storage::forgetDisk('s3');
}

describe('s3 disk config', function () {
    it('has a temporary_url key separate from url', function () {
        expect(config('filesystems.disks.s3'))->toHaveKey('temporary_url');
    });

    it('configures the real S3 client with when_required checksums', function () {
        configureS3(['region' => 'auto', 'endpoint' => 'https://acct.r2.cloudflarestorage.com']);

        $client = Storage::disk('s3')->getClient();

        expect($client->getConfig('request_checksum_calculation'))->toBe('when_required')
            ->and($client->getConfig('response_checksum_validation'))->toBe('when_required');
    });

    it('presigns against the endpoint host when no temporary url is set', function () {
        configureS3([
            'region' => 'auto',
            'endpoint' => 'https://acct.r2.cloudflarestorage.com',
            'temporary_url' => null,
            'use_path_style_endpoint' => false,
        ]);

        $url = app(StorageService::class)->presignedUrl('a/b.bin');

        expect(parse_url($url, PHP_URL_HOST))->toContain('acct.r2.cloudflarestorage.com');
    });

    it('presigns against AWS_TEMPORARY_URL when set', function () {
        configureS3([
            'region' => 'garage',
            'endpoint' => 'http://garage:3900',
            'temporary_url' => 'https://app.example.test',
            'use_path_style_endpoint' => true,
        ]);

        $url = app(StorageService::class)->presignedUrl('a/b.bin');

        expect(parse_url($url, PHP_URL_HOST))->toBe('app.example.test')
            ->and(parse_url($url, PHP_URL_PATH))->toBe('/photos/a/b.bin');
    });
});

describe('storage:check', function () {
    beforeEach(function () {
        config(['app.frontend_url' => 'https://app.example.test']);
        stubS3Disk();
    });

    it('passes when the object round-trips with CORS', function () {
        fakeStorageHttp(['Access-Control-Allow-Origin' => 'https://app.example.test']);

        $this->artisan('storage:check')
            ->expectsOutputToContain('Storage check passed.')
            ->assertSuccessful();

        expect(Storage::disk('s3')->allFiles())->toBeEmpty();
    });

    it('fetches via the endpoint and skips CORS when a temporary url proxy is configured', function () {
        config([
            'filesystems.disks.s3.temporary_url' => 'https://bucket.example-storage.test',
            'filesystems.disks.s3.endpoint' => 'http://garage:3900',
        ]);
        fakeStorageHttp([]);

        $this->artisan('storage:check')
            ->expectsOutputToContain('CORS check skipped')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://garage:3900/'));
    });

    it('fails with a CORS hint when the header is missing', function () {
        fakeStorageHttp([]);

        $this->artisan('storage:check')
            ->expectsOutputToContain('no Access-Control-Allow-Origin')
            ->assertFailed();

        expect(Storage::disk('s3')->allFiles())->toBeEmpty();
    });

    it('fails when the allowed origin does not match', function () {
        fakeStorageHttp(['Access-Control-Allow-Origin' => 'https://other.test']);

        $this->artisan('storage:check')->expectsOutputToContain('https://other.test')->assertFailed();
    });

    it('explains signature errors from the presigned GET', function () {
        Http::fake(['*' => Http::response('<Error><Code>SignatureDoesNotMatch</Code></Error>', 403)]);

        $this->artisan('storage:check')->expectsOutputToContain('SignatureDoesNotMatch')->assertFailed();
    });

    it('explains an unknown access key', function () {
        Http::fake(['*' => Http::response('<Error><Code>InvalidAccessKeyId</Code></Error>', 403)]);

        $this->artisan('storage:check')->expectsOutputToContain('InvalidAccessKeyId')->assertFailed();
    });

    it('explains a missing bucket', function () {
        Http::fake(['*' => Http::response('<Error><Code>NoSuchBucket</Code></Error>', 404)]);

        $this->artisan('storage:check')->expectsOutputToContain('NoSuchBucket')->assertFailed();
    });

    it('reports connection failures', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->artisan('storage:check')->expectsOutputToContain('Could not reach the presigned URL host')->assertFailed();
    });
});
