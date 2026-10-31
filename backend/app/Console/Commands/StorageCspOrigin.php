<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prints the origin(s) the browser talks to for presigned storage URLs, so the
 * reverse proxy can add them to the Content-Security-Policy connect-src.
 *
 * Presigning is a local signature computation: no request reaches the bucket.
 * The production entrypoint runs this with --write on every backend start and
 * the bundled Caddy reads the file per request (docker/caddy/Caddyfile).
 */
class StorageCspOrigin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:csp-origin {--write= : Write the origin(s) to this file instead of printing them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print the object storage origin(s) the browser needs in the Content-Security-Policy connect-src';

    /**
     * scheme://host[:port], the only shape a CSP host-source may take here.
     * Anything else (including newlines) never reaches the header.
     */
    private const ORIGIN_PATTERN = '/^https?:\/\/(?:[a-z0-9.-]+|\[[0-9a-f:.]+\])(?::\d{1,5})?$/';

    public function handle(StorageService $storage): int
    {
        try {
            $origins = $this->presignedOrigins($storage);
        } catch (Throwable $e) {
            $this->components->error('Could not presign a storage URL: '.$e->getMessage());
            $origins = null;
        }

        $line = implode(' ', $origins ?? []);
        $file = $this->option('write');

        if (is_string($file) && $file !== '') {
            if (! $this->writeAtomically($file, $line)) {
                $this->components->error("Could not write {$file}.");

                return self::FAILURE;
            }
        } else {
            $this->line($line);
        }

        return $origins === null ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function presignedOrigins(StorageService $storage): array
    {
        $path = 'csp-origin-probe.bin';
        $urls = [
            $storage->presignedUrl($path),
            $storage->presignedPutUrl($path)['url'],
        ];

        $origins = [];
        foreach ($urls as $url) {
            $origin = $this->originOf($url);
            if ($origin === null) {
                throw new \RuntimeException('Presigned URL has no usable origin.');
            }
            $origins[$origin] = true;
        }

        return array_keys($origins);
    }

    protected function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $origin = $scheme.'://'.$host.($port !== null && $port !== $defaultPort ? ':'.$port : '');

        return preg_match(self::ORIGIN_PATTERN, $origin) === 1 ? $origin : null;
    }

    protected function writeAtomically(string $file, string $contents): bool
    {
        $directory = dirname($file);
        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        $temporary = $file.'.tmp';

        return file_put_contents($temporary, $contents."\n") !== false && rename($temporary, $file);
    }
}
