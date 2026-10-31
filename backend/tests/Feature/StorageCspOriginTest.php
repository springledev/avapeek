<?php

use Illuminate\Support\Facades\Storage;

/**
 * Point the real s3 disk (local SigV4 presigning, no network) at the given settings.
 *
 * @param  array<string, mixed>  $overrides
 */
function configureCspS3(array $overrides): void
{
    config(['filesystems.disks.s3' => array_merge(config('filesystems.disks.s3'), [
        'key' => 'k', 'secret' => 's', 'bucket' => 'photos',
    ], $overrides)]);
    Storage::forgetDisk('s3');
}

it('prints the app origin for the Garage proxy setup', function () {
    configureCspS3([
        'region' => 'garage',
        'endpoint' => 'http://garage:3900',
        'temporary_url' => 'https://photos.example.test',
        'use_path_style_endpoint' => true,
    ]);

    $this->artisan('storage:csp-origin')
        ->expectsOutput('https://photos.example.test')
        ->assertSuccessful();
});

it('keeps a non-default port', function () {
    configureCspS3([
        'region' => 'garage',
        'endpoint' => 'http://garage:3900',
        'temporary_url' => 'http://localhost:88',
        'use_path_style_endpoint' => true,
    ]);

    $this->artisan('storage:csp-origin')
        ->expectsOutput('http://localhost:88')
        ->assertSuccessful();
});

it('prints the virtual-hosted bucket origin for an external endpoint', function () {
    configureCspS3([
        'region' => 'auto',
        'bucket' => 'family',
        'endpoint' => 'https://acct.r2.cloudflarestorage.com',
        'temporary_url' => null,
        'use_path_style_endpoint' => false,
    ]);

    $this->artisan('storage:csp-origin')
        ->expectsOutput('https://family.acct.r2.cloudflarestorage.com')
        ->assertSuccessful();
});

it('prints the AWS bucket origin when no endpoint is set', function () {
    configureCspS3([
        'region' => 'eu-west-2',
        'bucket' => 'family',
        'endpoint' => null,
        'temporary_url' => null,
        'use_path_style_endpoint' => false,
    ]);

    $this->artisan('storage:csp-origin')
        ->expectsOutput('https://family.s3.eu-west-2.amazonaws.com')
        ->assertSuccessful();
});

it('writes the origin to a file', function () {
    configureCspS3([
        'region' => 'auto',
        'bucket' => 'family',
        'endpoint' => 'https://s3.us-west-004.backblazeb2.com',
        'temporary_url' => null,
        'use_path_style_endpoint' => false,
    ]);
    $file = sys_get_temp_dir().'/avapeek-csp-'.uniqid().'/storage-origin';
    mkdir(dirname($file));

    $this->artisan('storage:csp-origin', ['--write' => $file])->assertSuccessful();

    expect(file_get_contents($file))->toBe("https://family.s3.us-west-004.backblazeb2.com\n");

    unlink($file);
    rmdir(dirname($file));
});

it('writes an empty file and fails when presigning fails', function () {
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn () => throw new RuntimeException('no bucket'));
    $file = sys_get_temp_dir().'/avapeek-csp-'.uniqid().'/storage-origin';
    mkdir(dirname($file));

    $this->artisan('storage:csp-origin', ['--write' => $file])->assertFailed();

    expect(file_get_contents($file))->toBe("\n");

    unlink($file);
    rmdir(dirname($file));
});

it('rejects a presigned url that is not a plain origin', function () {
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn () => "https://evil.test\r\nX-Injected: 1/a");
    Storage::disk('s3')->buildTemporaryUploadUrlsUsing(fn () => ['url' => 'https://ok.test/a', 'headers' => []]);

    $this->artisan('storage:csp-origin')->assertFailed();
});
