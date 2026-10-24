<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CheckStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the S3 storage configuration: write, presigned GET with CORS, presigned PUT, delete';

    public function handle(StorageService $storage): int
    {
        $origin = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $path = '.storage-check/'.Str::uuid().'.txt';
        $body = 'avapeek-storage-check-'.Str::random(16);

        $this->line('Endpoint: '.(config('filesystems.disks.s3.endpoint') ?: '(AWS default)'));
        $this->line('Bucket:   '.config('filesystems.disks.s3.bucket'));
        $this->line('Region:   '.config('filesystems.disks.s3.region'));
        $this->line('Origin:   '.$origin);
        $this->newLine();

        try {
            if (Storage::disk('s3')->put($path, $body) === false) {
                $this->reportFailure($this->explainWriteFailure($path, $body));

                return self::FAILURE;
            }
            $this->info('[ok] wrote test object');

            try {
                return $this->verifyPresignedRead($storage, $path, $body, $origin);
            } finally {
                Storage::disk('s3')->delete($path);
            }
        } catch (Throwable $e) {
            $this->reportFailure($this->explainException($e));

            return self::FAILURE;
        }
    }

    protected function verifyPresignedRead(StorageService $storage, string $path, string $body, string $origin): int
    {
        $url = $storage->presignedUrl($path, 2);
        $this->info('[ok] generated presigned URL ('.parse_url($url, PHP_URL_HOST).')');

        $proxied = filled(config('filesystems.disks.s3.temporary_url')) && filled(config('filesystems.disks.s3.endpoint'));
        if ($proxied) {
            // The temporary URL host is only reachable from browsers (via the reverse proxy);
            // fetch from the real endpoint instead. The signature covers the endpoint host.
            $url = rtrim((string) config('filesystems.disks.s3.endpoint'), '/')
                .substr($url, strlen(rtrim((string) config('filesystems.disks.s3.temporary_url'), '/')));
            $this->line('[info] AWS_TEMPORARY_URL is set: fetching via AWS_ENDPOINT, CORS check skipped (same-origin proxy)');
            $this->line('[info] public URL is not tested here; see docs/storage.md ("What storage:check does not cover")');
        }

        try {
            $response = Http::withHeaders(['Origin' => $origin])->timeout(15)->get($url);
        } catch (ConnectionException $e) {
            $this->reportFailure('Could not reach the presigned URL host "'.parse_url($url, PHP_URL_HOST).'" ('.$e->getMessage().'). '
                .'Check AWS_ENDPOINT (and AWS_TEMPORARY_URL if you proxy through the app origin).');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->reportFailure($this->explainHttpFailure($response->status(), $response->body()));

            return self::FAILURE;
        }

        if ($response->body() !== $body) {
            $this->reportFailure('Presigned URL returned unexpected content. Is AWS_TEMPORARY_URL pointing at something other than the bucket?');

            return self::FAILURE;
        }
        $this->info('[ok] presigned GET returned the expected body');

        if ($proxied) {
            return $this->verifyPresignedWrite($storage, $origin, true);
        }

        $allowOrigin = $response->header('Access-Control-Allow-Origin');
        if ($allowOrigin === '' || ($allowOrigin !== '*' && $allowOrigin !== $origin)) {
            $this->reportFailure($allowOrigin === ''
                ? 'The response has no Access-Control-Allow-Origin header. Browsers will block photo downloads. '
                    .'Add a CORS rule to the bucket allowing GET/HEAD from '.$origin.' (see docs/storage.md).'
                : 'Access-Control-Allow-Origin is "'.$allowOrigin.'" but the frontend origin is "'.$origin.'". '
                    .'Update the bucket CORS rule or FRONTEND_URL.');

            return self::FAILURE;
        }
        $this->info('[ok] CORS allows origin '.$origin);

        return $this->verifyPresignedWrite($storage, $origin, false);
    }

    /**
     * Presigned PUT (direct browser uploads), plus a CORS preflight for external providers.
     * A failure only fails the command when UPLOADS_DIRECT is enabled.
     */
    protected function verifyPresignedWrite(StorageService $storage, string $origin, bool $proxied): int
    {
        $direct = (bool) config('avapeek.uploads.direct');
        $path = '.storage-check/'.Str::uuid().'.bin';
        $body = 'avapeek-storage-check-put-'.Str::random(16);

        $error = null;
        try {
            $error = $this->attemptPresignedWrite($storage, $path, $body, $origin, $proxied);
        } catch (Throwable $e) {
            $error = $this->explainException($e);
        } finally {
            try {
                Storage::disk('s3')->delete($path);
            } catch (Throwable) {
                // best effort
            }
        }

        $this->newLine();

        if ($error !== null) {
            if ($direct) {
                $this->reportFailure($error);

                return self::FAILURE;
            }
            $this->warn('[warn] '.$error);
            $this->warn('[warn] UPLOADS_DIRECT is disabled, so uploads use the PHP proxy and this does not block uploads.');
        }

        $this->info('Storage check passed.');

        return self::SUCCESS;
    }

    /**
     * @return string|null An error message, or null when the PUT round-trips.
     */
    protected function attemptPresignedWrite(StorageService $storage, string $path, string $body, string $origin, bool $proxied): ?string
    {
        $signed = $storage->presignedPutUrl($path, 2);
        $url = $signed['url'];
        $this->info('[ok] generated presigned PUT URL ('.parse_url($url, PHP_URL_HOST).')');

        if ($proxied) {
            $url = rtrim((string) config('filesystems.disks.s3.endpoint'), '/')
                .substr($url, strlen(rtrim((string) config('filesystems.disks.s3.temporary_url'), '/')));
        }

        if (! $proxied) {
            $requested = strtolower(implode(',', array_keys($signed['headers'])));
            try {
                $preflight = Http::withHeaders([
                    'Origin' => $origin,
                    'Access-Control-Request-Method' => 'PUT',
                    'Access-Control-Request-Headers' => $requested,
                ])->timeout(15)->send('OPTIONS', $url);
            } catch (ConnectionException $e) {
                return 'Could not reach the presigned PUT URL host ('.$e->getMessage().').';
            }

            $methods = strtoupper((string) $preflight->header('Access-Control-Allow-Methods'));
            if (! $preflight->successful() || ! (str_contains($methods, 'PUT') || trim($methods) === '*')) {
                return 'The CORS preflight for PUT was rejected (HTTP '.$preflight->status().'). Browsers will block direct uploads. '
                    .'Allow PUT from '.$origin.' with the '.implode(' and ', array_keys($signed['headers'])).' headers in the bucket CORS rule, '
                    .'or set UPLOADS_DIRECT=false (see docs/storage.md).';
            }
            $this->info('[ok] CORS preflight allows PUT');
        }

        try {
            $response = Http::withHeaders($signed['headers'] + ['Origin' => $origin])
                ->withBody($body, $signed['headers']['Content-Type'] ?? 'application/octet-stream')
                ->timeout(15)
                ->put($url);
        } catch (ConnectionException $e) {
            return 'Could not reach the presigned PUT URL host ('.$e->getMessage().').';
        }

        if (! $response->successful()) {
            return $this->explainHttpFailure($response->status(), $response->body(), 'PUT');
        }

        if ($storage->objectSize($path) !== strlen($body)) {
            return 'Presigned PUT succeeded but the object is missing or has the wrong size.';
        }
        $this->info('[ok] presigned PUT stored the expected object');

        return null;
    }

    protected function reportFailure(string $message): void
    {
        $this->error('[fail] '.$message);
    }

    /**
     * The s3 disk swallows errors (throw=false); retry once with a throwing disk to get the cause.
     */
    protected function explainWriteFailure(string $path, string $body): string
    {
        try {
            Storage::build(array_merge(config('filesystems.disks.s3'), ['throw' => true]))->put($path, $body);
        } catch (Throwable $e) {
            return $this->explainException($e);
        }

        return 'Writing the test object failed for an unknown reason.';
    }

    protected function explainException(Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'SignatureDoesNotMatch') => 'SignatureDoesNotMatch: the secret key is wrong, or the region / path-style setting does not match. '
                .'Check AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION and AWS_USE_PATH_STYLE_ENDPOINT.',
            str_contains($message, 'InvalidAccessKeyId') => 'InvalidAccessKeyId: AWS_ACCESS_KEY_ID is not recognised by this endpoint.',
            str_contains($message, 'NoSuchBucket') => 'NoSuchBucket: the bucket "'.config('filesystems.disks.s3.bucket').'" does not exist. Check AWS_BUCKET.',
            str_contains($message, 'AccessDenied') => 'AccessDenied: the credentials were rejected or the key cannot write to this bucket. Check AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY and grant the key read/write/delete on the bucket.',
            str_contains($message, 'cURL error 6'), str_contains($message, 'Could not resolve host') => 'DNS lookup failed. Check AWS_ENDPOINT.',
            str_contains($message, 'cURL error 7'), str_contains($message, 'Failed to connect'), str_contains($message, 'Connection refused') => 'Could not connect to the storage endpoint. Check AWS_ENDPOINT and that the service is running.',
            default => 'Storage error: '.Str::limit($message, 400),
        };
    }

    protected function explainHttpFailure(int $status, string $body, string $method = 'GET'): string
    {
        return match (true) {
            str_contains($body, 'SignatureDoesNotMatch') => 'Presigned URL rejected with SignatureDoesNotMatch. The signed host differs from the host requested: '
                .'check AWS_TEMPORARY_URL (proxy setups), AWS_DEFAULT_REGION and AWS_USE_PATH_STYLE_ENDPOINT.',
            str_contains($body, 'InvalidAccessKeyId') => 'Presigned URL rejected with InvalidAccessKeyId. Check AWS_ACCESS_KEY_ID.',
            str_contains($body, 'NoSuchBucket') => 'Presigned URL points at a missing bucket (NoSuchBucket). Check AWS_BUCKET and path-style setting.',
            $status === 403 && $method === 'PUT' => 'Presigned PUT returned 403. The key may lack s3:PutObject, or the Content-Type / Cache-Control headers do not match what was signed. Check credentials, bucket permissions and CORS.',
            $status === 403 => 'Presigned GET returned 403. Check credentials, region and path-style setting.',
            $status === 404 => 'Presigned '.$method.' returned 404. Check AWS_TEMPORARY_URL / AWS_ENDPOINT and path-style setting.',
            default => 'Presigned '.$method.' failed with HTTP '.$status.'.',
        };
    }
}
