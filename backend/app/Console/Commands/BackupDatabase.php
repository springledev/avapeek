<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class BackupDatabase extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:database';

    /**
     * @var string
     */
    protected $description = 'Dump the PostgreSQL database (optionally age-encrypted) to the backup disk and prune old dumps';

    public function handle(BackupService $backups): int
    {
        $temps = [];
        $password = '';

        try {
            $config = $backups->postgresConfig();
            $password = $config['password'];
            $recipient = config('avapeek.backup.age_recipient');

            if ($recipient !== null && ! preg_match('/^age1[0-9a-z]+$/', (string) $recipient)) {
                throw new RuntimeException('BACKUP_AGE_RECIPIENT is not a valid age recipient (expected age1...).');
            }

            if ($backups->sharesPhotoStorage()) {
                $this->warn('WARNING: backups share a failure domain with photos (same bucket and endpoint). One incident can lose both. Set BACKUP_AWS_* to a separate destination.');
            }

            $dump = $temps[] = $backups->tempFile();
            $this->runProcess([
                'pg_dump', '--format=custom', '--no-owner', '--no-acl',
                '--host='.$config['host'], '--port='.$config['port'],
                '--username='.$config['username'], '--dbname='.$config['database'],
                '--file='.$dump,
            ], $backups->postgresEnvironment($config), 1800, 'pg_dump', $password, $backups);

            if (! is_file($dump) || filesize($dump) === 0) {
                throw new RuntimeException('pg_dump produced no output.');
            }
            $this->line('[ok] database dumped');

            $upload = $dump;
            if ($recipient !== null) {
                $upload = $temps[] = $backups->tempFile();
                $this->runProcess(['age', '-r', (string) $recipient, '-o', $upload, $dump], [], 600, 'age', $password, $backups);
                $this->line('[ok] dump encrypted with age');
            }

            $key = $backups->newKey($recipient !== null);
            $this->upload($backups, $upload, $key);
            $this->info('Backup written: '.$key.' ('.filesize($upload).' bytes)');
        } catch (Throwable $e) {
            $message = $backups->redact($e->getMessage(), $password);
            Log::error('backup:database failed: '.$message);
            $this->error('[fail] '.$message);

            return self::FAILURE;
        } finally {
            $backups->removeFiles($temps);
        }

        try {
            foreach ($backups->prune() as $old) {
                $this->line('[pruned] '.$old);
            }
        } catch (Throwable $e) {
            Log::error('backup:database prune failed: '.$e->getMessage());
            $this->error('[fail] backup stored, but pruning failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function runProcess(array $command, array $env, int $timeout, string $label, string $password, BackupService $backups): void
    {
        $result = Process::timeout($timeout)->env($env)->run($command);

        if ($result->failed()) {
            $detail = $backups->redact(trim($result->errorOutput()), $password);
            throw new RuntimeException($label.' failed (exit '.$result->exitCode().')'.($detail !== '' ? ': '.$detail : ''));
        }
    }

    protected function upload(BackupService $backups, string $file, string $key): void
    {
        $disk = $backups->disk();
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not read the dump file.');
        }

        try {
            $written = $disk->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }

        if ($written === false || $disk->size($key) !== filesize($file)) {
            $disk->delete($key);
            throw new RuntimeException('Uploading the backup to the "backup" disk failed (check BACKUP_AWS_* or the AWS_* fallback; run storage:check --backup).');
        }
    }
}
