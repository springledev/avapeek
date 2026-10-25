<?php

use App\Services\BackupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

const BACKUP_TEST_PASSWORD = 'sup3r-secret-pw';
const BACKUP_TEST_RECIPIENT = 'age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

function backupKey(int $daysAgo, string $suffix = '.dump'): string
{
    return 'backups/db/avapeek-'.CarbonImmutable::now('UTC')->subDays($daysAgo)->format('Ymd\THis\Z').$suffix;
}

function configurePostgres(): void
{
    config([
        'avapeek.backup.connection' => 'pgsql',
        'database.connections.pgsql.host' => 'db.internal',
        'database.connections.pgsql.port' => '5432',
        'database.connections.pgsql.database' => 'avapeek',
        'database.connections.pgsql.username' => 'avapeek_user',
        'database.connections.pgsql.password' => BACKUP_TEST_PASSWORD,
        'database.connections.pgsql.url' => null,
    ]);
}

/**
 * Fake pg_dump/age: write output files like the real binaries and record temp file paths and modes.
 *
 * @return object{files: array<int, string>, modes: array<int, string>}
 */
function fakeBackupProcesses(int $dumpExit = 0, string $dumpStderr = ''): object
{
    $state = (object) ['files' => [], 'modes' => []];

    Process::fake(function ($process) use ($state, $dumpExit, $dumpStderr) {
        $command = $process->command;

        if ($command[0] === 'pg_dump') {
            foreach ($command as $arg) {
                if (str_starts_with($arg, '--file=')) {
                    $path = substr($arg, 7);
                    $state->files[] = $path;
                    $state->modes[] = decoct(fileperms($path) & 0777);
                    if ($dumpExit === 0) {
                        file_put_contents($path, 'PGDMP-fake-dump');
                    }
                }
            }

            return Process::result(errorOutput: $dumpStderr, exitCode: $dumpExit);
        }

        if ($command[0] === 'age') {
            $out = $command[array_search('-o', $command, true) + 1];
            $state->files[] = $out;
            file_put_contents($out, "age-encryption.org/v1\nfake-ciphertext");
        }

        return Process::result();
    });

    return $state;
}

beforeEach(function () {
    configurePostgres();
    config([
        'avapeek.backup.retention_days' => 14,
        'avapeek.backup.keep_minimum' => 3,
        'avapeek.backup.age_recipient' => null,
    ]);
});

describe('backup:database', function () {
    it('runs pg_dump as an argv array with the password only in env and uploads a named key', function () {
        $disk = Storage::fake('backup');
        $state = fakeBackupProcesses();

        $this->artisan('backup:database')->assertSuccessful();

        Process::assertRan(function ($process) {
            return is_array($process->command)
                && $process->command[0] === 'pg_dump'
                && in_array('--format=custom', $process->command, true)
                && in_array('--host=db.internal', $process->command, true)
                && ($process->environment['PGPASSWORD'] ?? null) === BACKUP_TEST_PASSWORD
                && ! str_contains(implode(' ', $process->command), BACKUP_TEST_PASSWORD);
        });

        $keys = $disk->allFiles();
        expect($keys)->toHaveCount(1)
            ->and($keys[0])->toMatch('#^backups/db/avapeek-\d{8}T\d{6}Z\.dump$#')
            ->and($disk->get($keys[0]))->toBe('PGDMP-fake-dump');
        expect($state->modes)->toBe(['600']);
        expect(file_exists($state->files[0]))->toBeFalse();
    });

    it('fails without uploading or pruning when pg_dump fails, redacts the password and cleans up', function () {
        $disk = Storage::fake('backup');
        $old = [backupKey(40), backupKey(41), backupKey(42), backupKey(43), backupKey(44)];
        foreach ($old as $key) {
            $disk->put($key, 'old');
        }
        $state = fakeBackupProcesses(1, 'pg_dump: error: password '.BACKUP_TEST_PASSWORD.' rejected');

        Log::spy();

        $this->artisan('backup:database')
            ->doesntExpectOutputToContain(BACKUP_TEST_PASSWORD)
            ->expectsOutputToContain('pg_dump failed')
            ->assertFailed();
        Log::shouldHaveReceived('error')->once();
        Log::shouldNotHaveReceived('error', fn ($message) => str_contains((string) $message, BACKUP_TEST_PASSWORD));
        expect($disk->allFiles())->toEqualCanonicalizing($old);
        expect(file_exists($state->files[0]))->toBeFalse();
    });

    it('prunes old dumps, keeps the newest three and ignores foreign keys', function () {
        $disk = Storage::fake('backup');
        fakeBackupProcesses();
        foreach ([1, 16, 17, 18, 30] as $days) {
            $disk->put(backupKey($days), 'x');
        }
        $disk->put(backupKey(31, '.dump.age'), 'x');
        $disk->put('backups/db/notes.txt', 'keep');
        $disk->put('backups/db/avapeek-20200101T000000Z.dump.bak', 'keep');
        $disk->put('photos/11111111-1111-1111-1111-111111111111.jpg.enc', 'keep');

        $this->artisan('backup:database')->assertSuccessful();

        // newest three dumps (new, 1d, 16d) survive; 17d, 18d, 30d, 31d are pruned
        $disk->assertExists(backupKey(1));
        $disk->assertExists(backupKey(16));
        foreach ([17, 18, 30] as $days) {
            $disk->assertMissing(backupKey($days));
        }
        $disk->assertMissing(backupKey(31, '.dump.age'));
        $disk->assertExists('backups/db/notes.txt');
        $disk->assertExists('backups/db/avapeek-20200101T000000Z.dump.bak');
        $disk->assertExists('photos/11111111-1111-1111-1111-111111111111.jpg.enc');
    });

    it('always keeps the newest three dumps regardless of age', function () {
        $disk = Storage::fake('backup');
        foreach ([30, 40, 50, 60] as $days) {
            $disk->put(backupKey($days), 'x');
        }

        $deleted = app(BackupService::class)->prune();

        expect($deleted)->toBe([backupKey(60)]);
        $disk->assertExists(backupKey(30));
        $disk->assertExists(backupKey(40));
        $disk->assertExists(backupKey(50));
    });

    it('prunes nothing when retention is zero', function () {
        $disk = Storage::fake('backup');
        fakeBackupProcesses();
        $old = [backupKey(100), backupKey(200), backupKey(300), backupKey(400)];
        foreach ($old as $key) {
            $disk->put($key, 'x');
        }
        config(['avapeek.backup.retention_days' => 0]);

        $this->artisan('backup:database')->assertSuccessful();

        expect($disk->allFiles())->toHaveCount(5);
    });

    it('encrypts with age -r and uploads a .age key without touching any identity', function () {
        $disk = Storage::fake('backup');
        $state = fakeBackupProcesses();
        config(['avapeek.backup.age_recipient' => BACKUP_TEST_RECIPIENT]);

        $this->artisan('backup:database')->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command[0] === 'age'
            && $process->command[1] === '-r'
            && $process->command[2] === BACKUP_TEST_RECIPIENT
            && ! in_array('-i', $process->command, true)
            && ! in_array('-d', $process->command, true));

        $keys = $disk->allFiles();
        expect($keys)->toHaveCount(1)
            ->and($keys[0])->toMatch('#^backups/db/avapeek-\d{8}T\d{6}Z\.dump\.age$#')
            ->and($disk->get($keys[0]))->toStartWith('age-encryption.org/v1');
        foreach ($state->files as $file) {
            expect(file_exists($file))->toBeFalse();
        }
    });

    it('rejects an invalid age recipient before running anything', function () {
        Storage::fake('backup');
        Process::fake();
        config(['avapeek.backup.age_recipient' => '-o /etc/passwd']);

        expect(Artisan::call('backup:database'))->toBe(1);
        Process::assertNothingRan();
    });

    it('warns loudly when backups share the photo bucket and endpoint', function () {
        config([
            'filesystems.disks.s3.bucket' => 'photos',
            'filesystems.disks.s3.endpoint' => 'http://garage:3900',
            'filesystems.disks.backup.bucket' => 'photos',
            'filesystems.disks.backup.endpoint' => 'http://garage:3900',
        ]);
        Storage::set('backup', Storage::fake('backup-memory'));
        fakeBackupProcesses();

        $this->artisan('backup:database')
            ->expectsOutputToContain('WARNING: backups share a failure domain with photos')
            ->assertSuccessful();
    });

    it('does not warn for a separate destination', function () {
        config([
            'filesystems.disks.s3.bucket' => 'photos',
            'filesystems.disks.backup.bucket' => 'backups',
        ]);

        expect(app(BackupService::class)->sharesPhotoStorage())->toBeFalse();
    });

    it('fails when the default connection is not PostgreSQL', function () {
        Storage::fake('backup');
        Process::fake();
        config(['avapeek.backup.connection' => 'sqlite']);

        expect(Artisan::call('backup:database'))->toBe(1);
        Process::assertNothingRan();
    });
});

describe('backup disk config', function () {
    function backupDiskConfig(array $env): array
    {
        $names = ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET', 'AWS_ENDPOINT',
            'AWS_USE_PATH_STYLE_ENDPOINT', 'BACKUP_AWS_ACCESS_KEY_ID', 'BACKUP_AWS_SECRET_ACCESS_KEY',
            'BACKUP_AWS_DEFAULT_REGION', 'BACKUP_AWS_BUCKET', 'BACKUP_AWS_ENDPOINT', 'BACKUP_AWS_USE_PATH_STYLE_ENDPOINT'];
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        try {
            foreach ($env as $name => $value) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }

            return (require config_path('filesystems.php'))['disks'];
        } finally {
            foreach ($names as $name) {
                putenv($name);
                unset($_ENV[$name]);
                if ($previous[$name] !== false) {
                    putenv("{$name}={$previous[$name]}");
                }
            }
        }
    }

    $photoEnv = [
        'AWS_ACCESS_KEY_ID' => 'photo-key', 'AWS_SECRET_ACCESS_KEY' => 'photo-secret', 'AWS_DEFAULT_REGION' => 'garage',
        'AWS_BUCKET' => 'photos', 'AWS_ENDPOINT' => 'http://garage:3900', 'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
    ];

    it('falls back to the s3 settings when BACKUP_AWS_BUCKET is unset or empty', function () use ($photoEnv) {
        foreach ([[], ['BACKUP_AWS_BUCKET' => '', 'BACKUP_AWS_ACCESS_KEY_ID' => 'ignored']] as $extra) {
            $disks = backupDiskConfig($photoEnv + $extra);

            expect($disks['backup'])->toMatchArray([
                'driver' => 's3',
                'key' => 'photo-key',
                'secret' => 'photo-secret',
                'bucket' => 'photos',
                'endpoint' => 'http://garage:3900',
                'use_path_style_endpoint' => true,
                'request_checksum_calculation' => 'when_required',
                'response_checksum_validation' => 'when_required',
            ]);
        }
    });

    it('uses only BACKUP_AWS_* when BACKUP_AWS_BUCKET is set', function () use ($photoEnv) {
        $disks = backupDiskConfig($photoEnv + [
            'BACKUP_AWS_BUCKET' => 'offsite', 'BACKUP_AWS_ACCESS_KEY_ID' => 'bk-key',
            'BACKUP_AWS_SECRET_ACCESS_KEY' => 'bk-secret', 'BACKUP_AWS_DEFAULT_REGION' => 'us-west-004',
            'BACKUP_AWS_ENDPOINT' => '',
        ]);

        expect($disks['backup'])->toMatchArray([
            'key' => 'bk-key', 'secret' => 'bk-secret', 'region' => 'us-west-004', 'bucket' => 'offsite',
            'endpoint' => null, 'use_path_style_endpoint' => false,
        ]);
    });
});

describe('backup schedule', function () {
    it('schedules backup:database at 02:30 UTC and a weekly verify', function () {
        $events = collect(app(Schedule::class)->events());

        $backup = $events->first(fn ($e): bool => str_contains($e->command ?? '', 'backup:database'));
        $verify = $events->first(fn ($e): bool => str_contains($e->command ?? '', 'backup:verify'));

        expect($backup)->not->toBeNull()
            ->and($backup->expression)->toBe('30 2 * * *')
            ->and($backup->timezone)->toBe('UTC')
            ->and($backup->onOneServer)->toBeTrue()
            ->and($backup->withoutOverlapping)->toBeTrue()
            ->and($verify)->not->toBeNull()
            ->and($verify->command)->not->toContain('--restore')
            ->and($verify->command)->toContain('--quick --allow-empty');
    });
});

describe('backup:verify', function () {
    it('fails clearly when there are no backups', function () {
        Storage::fake('backup')->put('backups/db/notes.txt', 'x');
        Process::fake();

        $this->artisan('backup:verify')
            ->expectsOutputToContain('No backups found')
            ->assertFailed();
    });

    it('succeeds with a logged warning on an empty prefix with --allow-empty', function () {
        Storage::fake('backup');
        Process::fake();
        Log::spy();

        $this->artisan('backup:verify', ['--allow-empty' => true])->assertSuccessful();

        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');
    });

    it('quick mode checks the header without downloading or running pg_restore', function () {
        $disk = Storage::fake('backup');
        $disk->put(backupKey(1), 'PGDMP-fake');
        Process::fake();

        $this->artisan('backup:verify', ['--quick' => true])
            ->expectsOutputToContain('PGDMP header present')
            ->assertSuccessful();
        Process::assertNothingRan();

        $disk->put(backupKey(0), 'not a dump');
        $this->artisan('backup:verify', ['--quick' => true])->assertFailed();
    });

    it('lists the newest dump with pg_restore --list', function () {
        $disk = Storage::fake('backup');
        $disk->put(backupKey(3), 'PGDMP-old');
        $disk->put(backupKey(1), 'PGDMP-new');
        Process::fake();

        $this->artisan('backup:verify')
            ->expectsOutputToContain(backupKey(1))
            ->expectsOutputToContain('Backup verified.')
            ->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command[0] === 'pg_restore' && $process->command[1] === '--list');
    });

    it('fails when pg_restore cannot read the dump', function () {
        Storage::fake('backup')->put(backupKey(1), 'garbage');
        Process::fake(fn () => Process::result(errorOutput: 'not a valid archive', exitCode: 1));

        $this->artisan('backup:verify')
            ->expectsOutputToContain('pg_restore --list failed')
            ->assertFailed();
    });

    it('only checks the age header of an encrypted dump without an identity', function () {
        Storage::fake('backup')->put(backupKey(1, '.dump.age'), "age-encryption.org/v1\n-> X25519 abc\nbody");
        Process::fake();

        $this->artisan('backup:verify')
            ->expectsOutputToContain('age header present')
            ->expectsOutputToContain('--identity')
            ->assertSuccessful();

        Process::assertNothingRan();
    });

    it('fails an encrypted dump that has no age header', function () {
        Storage::fake('backup')->put(backupKey(1, '.dump.age'), 'PGDMP-not-encrypted');
        Process::fake();

        $this->artisan('backup:verify')->assertFailed();
    });

    it('decrypts with the identity file before listing', function () {
        Storage::fake('backup')->put(backupKey(1, '.dump.age'), "age-encryption.org/v1\nbody");
        $identity = tempnam(sys_get_temp_dir(), 'id');
        Process::fake(function ($process) {
            if ($process->command[0] === 'age') {
                file_put_contents($process->command[array_search('-o', $process->command, true) + 1], 'PGDMP');
            }

            return Process::result();
        });

        try {
            $this->artisan('backup:verify', ['--identity' => $identity])->assertSuccessful();
        } finally {
            unlink($identity);
        }

        Process::assertRan(fn ($process) => $process->command[0] === 'age' && $process->command[1] === '-d');
        Process::assertRan(fn ($process) => $process->command[0] === 'pg_restore');
    });

    it('refuses --restore when the default connection is not PostgreSQL', function () {
        Storage::fake('backup')->put(backupKey(1), 'PGDMP');
        Process::fake();
        config(['avapeek.backup.connection' => 'sqlite']);

        $this->artisan('backup:verify', ['--restore' => true])
            ->expectsOutputToContain('pgsql driver')
            ->assertFailed();
    });
});

describe('storage:check --backup', function () {
    it('round-trips an object on the backup disk', function () {
        Storage::fake('s3');
        $backup = Storage::fake('backup');
        Http::fake();

        $this->artisan('storage:check', ['--backup' => true])
            ->expectsOutputToContain('Backup disk check passed.');

        expect($backup->allFiles())->toBe([]);
    });

    it('fails when the backup disk cannot be written', function () {
        Storage::fake('s3');
        $mock = Mockery::mock(Storage::fake('backup'))->makePartial();
        $mock->shouldReceive('put')->andReturn(false);
        Storage::set('backup', $mock);

        $this->artisan('storage:check', ['--backup' => true])
            ->expectsOutputToContain('Writing to the backup disk failed')
            ->assertFailed();
    });
});
