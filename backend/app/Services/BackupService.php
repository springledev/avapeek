<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackupService
{
    public function disk(): Filesystem
    {
        return Storage::disk('backup');
    }

    public function prefix(): string
    {
        return trim((string) config('avapeek.backup.prefix'), '/');
    }

    /**
     * Object key for a new dump, e.g. backups/db/avapeek-20260101T023000Z.dump(.age).
     */
    public function newKey(bool $encrypted, ?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now('UTC');

        return $this->prefix().'/avapeek-'.$at->utc()->format('Ymd\THis\Z').'.dump'.($encrypted ? '.age' : '');
    }

    /**
     * Keys under the backup prefix that look like our dumps, newest first. Foreign keys are ignored.
     *
     * @return array<string, CarbonImmutable> key => dump timestamp (UTC)
     */
    public function listBackups(): array
    {
        $pattern = '#^'.preg_quote($this->prefix(), '#').'/avapeek-(\d{8}T\d{6}Z)\.dump(\.age)?$#';
        $found = [];

        foreach ($this->disk()->files($this->prefix()) as $key) {
            if (! preg_match($pattern, $key, $m)) {
                continue;
            }
            $at = CarbonImmutable::createFromFormat('Ymd\THis\Z', $m[1], 'UTC');
            if ($at !== false) {
                $found[$key] = $at;
            }
        }

        uksort($found, fn (string $a, string $b): int => [$found[$b], $b] <=> [$found[$a], $a]);

        return $found;
    }

    /**
     * Delete dumps older than the retention window, always keeping the newest N.
     *
     * @return list<string> deleted keys
     */
    public function prune(?CarbonImmutable $now = null): array
    {
        $days = (int) config('avapeek.backup.retention_days');
        if ($days <= 0) {
            return [];
        }

        $cutoff = ($now ?? CarbonImmutable::now('UTC'))->subDays($days);
        $keep = max(1, (int) config('avapeek.backup.keep_minimum'));
        $deleted = [];

        foreach (array_slice($this->listBackups(), $keep, null, true) as $key => $at) {
            if ($at->lt($cutoff) && $this->disk()->delete($key)) {
                $deleted[] = $key;
            }
        }

        return $deleted;
    }

    /**
     * True when the backup disk resolves to the same bucket and endpoint as the photos.
     */
    public function sharesPhotoStorage(): bool
    {
        $backup = config('filesystems.disks.backup');
        $photos = config('filesystems.disks.s3');

        return filled($backup['bucket'] ?? null)
            && ($backup['bucket'] ?? null) === ($photos['bucket'] ?? null)
            && (($backup['endpoint'] ?? null) ?: null) === (($photos['endpoint'] ?? null) ?: null);
    }

    public function connectionName(): string
    {
        return (string) (config('avapeek.backup.connection') ?: config('database.default'));
    }

    /**
     * Connection settings of the default (PostgreSQL) connection, with DB_URL resolved.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public function postgresConfig(): array
    {
        $config = config('database.connections.'.$this->connectionName());

        if (! is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Backups require the default database connection to use the pgsql driver.');
        }

        $config = (new ConfigurationUrlParser)->parseConfiguration($config);

        return [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (string) ($config['port'] ?? '5432'),
            'database' => (string) ($config['database'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ];
    }

    /**
     * Process environment carrying the password (never argv).
     *
     * @param  array{password: string}  $config
     * @return array<string, string>
     */
    public function postgresEnvironment(array $config): array
    {
        return $config['password'] === '' ? [] : ['PGPASSWORD' => $config['password']];
    }

    /**
     * Create an empty temp file readable only by the current user.
     */
    public function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'avapeek-bk-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }
        chmod($path, 0600);

        return $path;
    }

    /**
     * @param  list<string>  $paths
     */
    public function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function redact(string $text, string $password): string
    {
        return $password === '' ? $text : str_replace($password, '***', $text);
    }
}
