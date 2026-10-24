<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BackupVerify extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backup:verify {--restore : Restore into a throwaway database and print row counts} {--identity= : age identity file, needed to verify encrypted dumps}';

    /**
     * @var string
     */
    protected $description = 'Verify the newest database backup is readable (pg_restore --list, optionally a full test restore)';

    public function handle(BackupService $backups): int
    {
        $temps = [];
        $password = '';

        try {
            $all = $backups->listBackups();
            if ($all === []) {
                throw new RuntimeException('No backups found under "'.$backups->prefix().'/" on the backup disk.');
            }

            $key = array_key_first($all);
            $this->line('Newest backup: '.$key.' ('.$all[$key]->toIso8601String().')');

            $encrypted = str_ends_with($key, '.age');
            $identity = $this->option('identity');

            if ($encrypted && ! filled($identity)) {
                return $this->verifyEncryptedHeaderOnly($backups, $key);
            }

            $file = $temps[] = $backups->tempFile();
            $this->download($backups, $key, $file);
            $this->line('[ok] downloaded '.filesize($file).' bytes');

            if ($encrypted) {
                if (! is_file((string) $identity)) {
                    throw new RuntimeException('Identity file not found: '.$identity);
                }
                $plain = $temps[] = $backups->tempFile();
                $this->runProcess(['age', '-d', '-i', (string) $identity, '-o', $plain, $file], [], 600, 'age decrypt');
                $file = $plain;
                $this->line('[ok] decrypted with age');
            }

            $this->runProcess(['pg_restore', '--list', $file], [], 300, 'pg_restore --list');
            $this->line('[ok] pg_restore --list reads the archive');

            if ($this->option('restore')) {
                $password = $backups->postgresConfig()['password'];
                $this->testRestore($backups, $file);
            }

            Log::info('backup:verify passed for '.$key);
            $this->info('Backup verified.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $message = $backups->redact($e->getMessage(), $password);
            Log::error('backup:verify failed: '.$message);
            $this->error('[fail] '.$message);

            return self::FAILURE;
        } finally {
            $backups->removeFiles($temps);
        }
    }

    protected function verifyEncryptedHeaderOnly(BackupService $backups, string $key): int
    {
        $disk = $backups->disk();
        $stream = $disk->readStream($key);
        $header = is_resource($stream) ? (string) fgets($stream, 64) : '';
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (trim($header) !== 'age-encryption.org/v1') {
            Log::error('backup:verify failed: '.$key.' has no age header');
            $this->error('[fail] '.$key.' does not start with an age header.');

            return self::FAILURE;
        }

        $size = $disk->size($key);
        Log::info('backup:verify checked age header only for '.$key);
        $this->info('[ok] age header present, '.$size.' bytes');
        $this->warn('NOTICE: the dump is encrypted; pass --identity=<file> to verify its contents (keep the identity off the server, copy it in only temporarily).');

        return self::SUCCESS;
    }

    protected function download(BackupService $backups, string $key, string $target): void
    {
        $in = $backups->disk()->readStream($key);
        $out = fopen($target, 'wb');
        if (! is_resource($in) || $out === false) {
            throw new RuntimeException('Could not download '.$key.'.');
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        if (filesize($target) === 0) {
            throw new RuntimeException($key.' is empty.');
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    protected function runProcess(array $command, array $env, int $timeout, string $label): void
    {
        $result = Process::timeout($timeout)->env($env)->run($command);

        if ($result->failed()) {
            $detail = trim($result->errorOutput());
            throw new RuntimeException($label.' failed (exit '.$result->exitCode().')'.($detail !== '' ? ': '.$detail : ''));
        }
    }

    protected function testRestore(BackupService $backups, string $file): void
    {
        $config = $backups->postgresConfig();
        $name = 'avapeek_restore_test_'.Str::lower(Str::random(8));
        $created = false;

        try {
            DB::connection($backups->connectionName())->statement('CREATE DATABASE "'.$name.'"');
            $created = true;
            $this->line('[ok] created throwaway database '.$name);

            $this->runProcess([
                'pg_restore', '--no-owner', '--no-acl', '--exit-on-error',
                '--host='.$config['host'], '--port='.$config['port'],
                '--username='.$config['username'], '--dbname='.$name, $file,
            ], $backups->postgresEnvironment($config), 1800, 'pg_restore');
            $this->line('[ok] restored');

            config(['database.connections.restore_test' => array_merge(
                config('database.connections.'.$backups->connectionName()),
                ['url' => null, 'host' => $config['host'], 'port' => $config['port'], 'username' => $config['username'], 'password' => $config['password'], 'database' => $name],
            )]);

            foreach (['users', 'children', 'photos'] as $table) {
                if (! Schema::connection('restore_test')->hasTable($table)) {
                    throw new RuntimeException('Restored database is missing table "'.$table.'".');
                }
                $this->line(sprintf('  %-10s %d rows', $table, DB::connection('restore_test')->table($table)->count()));
            }
        } finally {
            DB::purge('restore_test');
            if ($created) {
                DB::connection($backups->connectionName())->statement('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
                $this->line('[ok] dropped '.$name);
            }
        }
    }
}
